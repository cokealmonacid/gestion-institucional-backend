<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Models\PlatformOperator;
use App\Platform\Models\PlatformRecoveryCode;
use App\Platform\PlatformException;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAuthentication;
use App\Platform\Services\PlatformOperatorProvisioning;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;

class PlatformOperatorDeactivationTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
    }

    private function input(PlatformOperator $target): array
    {
        return ['operator_id' => $target->id, 'operation_id' => (string) Str::uuid(), 'reason' => 'Confirmed operator departure',
            'accountability' => 'trusted-host:test-operations', 'confirmation' => true];
    }

    public function test_deactivation_revokes_all_platform_credentials_without_touching_institutional_identity_or_history(): void
    {
        $this->operator();
        $target = $this->operator();
        $oldToken = $this->token($target);
        $challenge = app(PlatformAuthentication::class)->login($target->email, 'Valid-test-password-123');
        [$link, $proof] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($target, 'password_reset'));
        PlatformRecoveryCode::create(['operator_id' => $target->id, 'code_hash' => Hash::make('synthetic-recovery')]);
        $target->update(['pending_totp_secret' => 'JBSWY3DPEHPK3PXP', 'enrollment_expires_at' => now()->addMinutes(5)]);
        $password = $target->password;
        $institutional = User::factory()->for(Institution::factory()->create())->create(['id' => $target->id]);
        $institutional->createToken('separate-identity');
        DB::table('sessions')->insert(['id' => 'separate-institutional-session', 'user_id' => $institutional->id, 'payload' => '', 'last_activity' => time()]);
        $input = $this->input($target);
        $result = app(PlatformOperatorProvisioning::class)->execute('deactivate', $input);
        $this->assertSame('not_applicable', $result['publication_status']);
        $this->assertFalse($target->fresh()->active);
        $this->assertSame($password, $target->fresh()->password);
        $this->assertNotNull($target->fresh()->verified_at);
        $this->assertSame(1, $target->fresh()->auth_epoch);
        $this->assertNull($target->fresh()->pending_totp_secret);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNull($link->fresh()->token_hash);
        $this->assertDatabaseMissing('platform_recovery_codes', ['operator_id' => $target->id, 'consumed_at' => null]);
        $this->assertDatabaseMissing('platform_auth_challenges', ['operator_id' => $target->id, 'consumed_at' => null]);
        $this->assertDatabaseHas('administrative_events', ['actor_kind' => 'operator_cli', 'actor_id' => $input['accountability'], 'action' => 'operator.deactivate', 'subject_id' => $target->id, 'operation_id' => $input['operation_id'], 'reason' => $input['reason']]);
        $this->assertSame(1, $institutional->tokens()->count());
        $this->assertDatabaseHas('sessions', ['id' => 'separate-institutional-session']);
        $this->withToken($oldToken)->getJson('/api/v1/platform/plans')->assertUnauthorized();
        $this->postJson('/api/v1/platform/auth/login', ['email' => $target->email, 'password' => 'Valid-test-password-123'])->assertForbidden()->assertJsonPath('error.code', 'PLATFORM_OPERATOR_INACTIVE');
        $this->postJson('/api/v1/platform/auth/mfa/challenge', ['challenge_id' => $challenge['challenge_id'], 'challenge_token' => $challenge['challenge_token'], 'code' => $this->code($target->totp_secret)])->assertStatus(400)->assertJsonPath('error.code', 'MFA_CHALLENGE_INVALID');
        $this->postJson('/api/v1/platform/auth/password-recovery', ['email' => $target->email])->assertAccepted();
        $this->postJson('/api/v1/platform/auth/password-reset', ['email' => $target->email, 'token' => $proof, 'password' => 'New-synthetic-password-123', 'password_confirmation' => 'New-synthetic-password-123'])->assertStatus(400)->assertJsonPath('error.code', 'PLATFORM_LINK_INVALID');
        (new SendPlatformAccessLink($link->id, $link->generation, $proof))->handle();
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
    }

    public function test_last_usable_operator_is_protected_even_if_pending_or_incomplete_operators_exist(): void
    {
        $target = $this->operator();
        $pending = $this->operator(false);
        $pending->update(['password' => null, 'verified_at' => null]);
        try {
            app(PlatformOperatorProvisioning::class)->execute('deactivate', $this->input($target));
            $this->fail('Last usable operator was deactivated.');
        } catch (PlatformException $error) {
            $this->assertSame('PLATFORM_REQUIRES_USABLE_OPERATOR', $error->errorCode);
        }
        $this->assertTrue($target->fresh()->active);
        $this->assertSame(0, $target->fresh()->auth_epoch);
        $this->assertDatabaseCount('platform_cli_operations', 0);
    }

    public function test_pending_operator_cannot_reactivate_via_an_old_activation_link_or_cli_recovery(): void
    {
        $this->operator();
        $target = $this->operator(false);
        $target->update(['password' => null, 'verified_at' => null]);
        [$link, $proof] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($target, 'activation'));
        app(PlatformOperatorProvisioning::class)->execute('deactivate', $this->input($target));
        $this->postJson('/api/v1/platform/auth/activation', ['email' => $target->email, 'token' => $proof, 'password' => 'New-synthetic-password-123', 'password_confirmation' => 'New-synthetic-password-123'])->assertStatus(400)->assertJsonPath('error.code', 'PLATFORM_LINK_INVALID');
        foreach (['recover-mfa', 'reissue-access'] as $mode) {
            try {
                app(PlatformOperatorProvisioning::class)->execute($mode, $this->input($target));
                $this->fail('Inactive operator recovered access.');
            } catch (PlatformException $error) {
                $this->assertSame('PLATFORM_OPERATOR_INACTIVE', $error->errorCode);
            }
        }
        $this->assertNull($target->fresh()->verified_at);
        $this->assertNull($target->fresh()->password);
        $this->assertNull($link->fresh()->token_hash);
        $this->assertDatabaseCount('platform_operators', 2);
        Bus::assertNothingDispatched();
    }

    public function test_cli_requires_explicit_identification_accountability_reason_and_confirmation(): void
    {
        $this->operator();
        $target = $this->operator();
        $args = ['--operator-id' => $target->id, '--operation-id' => (string) Str::uuid(), '--reason' => 'Confirmed departure', '--accountability' => 'test-operations'];
        $this->artisan('platform:operator:deactivate', $args)->assertFailed();
        foreach (['--operator-id', '--operation-id', '--reason', '--accountability'] as $required) {
            $invalid = $args + ['--confirm' => true];
            unset($invalid[$required]);
            $this->artisan('platform:operator:deactivate', $invalid)->assertFailed();
        }
        $this->assertTrue($target->fresh()->active);
        $this->artisan('platform:operator:deactivate', $args + ['--confirm' => true])->expectsOutputToContain('not_applicable')->assertSuccessful();
        $this->assertFalse($target->fresh()->active);
        Bus::assertNothingDispatched();
    }

    public function test_same_operation_replays_once_and_changed_identity_or_reason_conflicts(): void
    {
        $remaining = $this->operator();
        $target = $this->operator();
        $input = $this->input($target);
        $service = app(PlatformOperatorProvisioning::class);
        $service->execute('deactivate', $input);
        $this->assertTrue($service->execute('deactivate', $input)['replayed']);
        foreach (['reason' => 'Different reason', 'operator_id' => $remaining->id] as $key => $value) {
            try {
                $service->execute('deactivate', array_replace($input, [$key => $value]));
                $this->fail('Different operation body replayed.');
            } catch (PlatformException $error) {
                $this->assertSame('IDEMPOTENCY_CONFLICT', $error->errorCode);
            }
        }
        $this->assertSame(1, $target->fresh()->auth_epoch);
        $this->assertDatabaseCount('platform_cli_operations', 1);
        $this->assertDatabaseCount('administrative_events', 1);
    }

    public function test_audit_failure_rolls_back_deactivation_credentials_and_receipt(): void
    {
        $this->operator();
        $target = $this->operator();
        $this->token($target);
        [$link] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($target, 'password_reset'));
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure'));
        try {
            app(PlatformOperatorProvisioning::class)->execute('deactivate', $this->input($target));
            $this->fail('Missing audit committed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertTrue($target->fresh()->active);
        $this->assertSame(0, $target->fresh()->auth_epoch);
        $this->assertSame(1, $target->tokens()->count());
        $this->assertNotNull($link->fresh()->token_hash);
        $this->assertDatabaseCount('platform_cli_operations', 0);
    }

    public function test_service_cannot_bypass_confirmation_and_fresh_deactivation_of_inactive_target_revokes_leftovers(): void
    {
        $target = $this->operator();
        $input = $this->input($target);
        unset($input['confirmation']);
        try {
            app(PlatformOperatorProvisioning::class)->execute('deactivate', $input);
            $this->fail('Confirmation bypassed.');
        } catch (PlatformException $error) {
            $this->assertSame('PLATFORM_DEACTIVATION_CONFIRMATION_REQUIRED', $error->errorCode);
        }
        $target->update(['active' => false]);
        $this->token($target);
        app(PlatformOperatorProvisioning::class)->execute('deactivate', $this->input($target));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertFalse($target->fresh()->active);
    }
}
