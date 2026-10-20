<?php

namespace Tests\Feature\Platform;

use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Models\PlatformAuthChallenge;
use App\Platform\Models\PlatformOperator;
use App\Platform\Models\PlatformRecoveryCode;
use App\Platform\PlatformException;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAuthentication;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PlatformAccessSecurityTest extends PlatformTestCase
{
    public function test_token_expiry_epoch_deactivation_and_cookie_fallback_are_enforced(): void
    {
        $operator = $this->operator();
        $token = $this->token($operator);
        $operator->tokens()->update(['expires_at' => now()->subSecond()]);
        $this->withToken($token)->getJson('/api/v1/platform/auth/profile')->assertUnauthorized();
        $token = $this->token($operator);
        $operator->increment('auth_epoch');
        $this->withToken($token)->getJson('/api/v1/platform/plans')->assertForbidden();
        $token = $this->token($operator);
        $operator->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/platform/plans')->assertForbidden();
        $this->withHeaders(['Authorization' => ''])->actingAs($operator)->getJson('/api/v1/platform/plans')->assertUnauthorized();
    }

    public function test_expired_enrollment_secret_is_discarded_and_wrong_purpose_is_rejected(): void
    {
        $operator = $this->operator(false);
        $auth = app(PlatformAuthentication::class);
        $challenge = $auth->login($operator->email, 'Valid-test-password-123');
        $input = array_intersect_key($challenge, array_flip(['challenge_id', 'challenge_token']));
        $auth->prove($input, 'enrollment');
        $this->assertNotNull($operator->fresh()->pending_totp_secret);
        $this->travel(6)->minutes();
        try {
            $auth->prove($input + ['code' => '000000'], 'confirmation');
            $this->fail();
        } catch (PlatformException) {
        }
        $this->assertNull($operator->fresh()->pending_totp_secret);
        $this->postJson('/api/v1/platform/auth/mfa/enrollment', ['challenge_id' => [], 'challenge_token' => 'fixture'])->assertUnprocessable();
    }

    public function test_recovery_consumption_rolls_back_if_audit_fails(): void
    {
        $operator = $this->operator();
        $plain = Str::random(32);
        $recovery = PlatformRecoveryCode::create(['operator_id' => $operator->id, 'code_hash' => Hash::make($plain)]);
        $challenge = app(PlatformAuthentication::class)->login($operator->email, 'Valid-test-password-123');
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(PlatformAuthentication::class)->prove(array_intersect_key($challenge, array_flip(['challenge_id', 'challenge_token'])) + ['recovery_code' => $plain], 'challenge');
            $this->fail();
        } catch (\RuntimeException) {
        }
        $this->assertNull($recovery->fresh()->consumed_at);
        $this->assertNull(PlatformAuthChallenge::first()->consumed_at);
        $this->assertNotNull($operator->fresh()->mfa_confirmed_at);
        $this->assertSame(0, $operator->fresh()->auth_epoch);
    }

    public function test_regenerated_codes_require_unused_totp_and_logout_revokes_current_token(): void
    {
        $operator = $this->operator();
        $token = $this->token($operator);
        $code = $this->code($operator->totp_secret);
        $this->withToken($token)->postJson('/api/v1/platform/auth/mfa/recovery-codes', ['current_password' => 'Valid-test-password-123', 'code' => $code])->assertOk()->assertJsonCount(10, 'data.recovery_codes');
        $this->withToken($token)->postJson('/api/v1/platform/auth/mfa/recovery-codes', ['current_password' => 'Valid-test-password-123', 'code' => $code])->assertStatus(400);
        $this->withToken($token)->postJson('/api/v1/platform/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/platform/auth/profile')->assertUnauthorized();
    }

    public function test_bootstrap_console_prints_only_safe_result_and_job_rejects_stale_links(): void
    {
        Bus::fake();
        $result = Artisan::call('platform:operator:bootstrap', ['--operation-id' => (string) Str::uuid(), '--name' => 'CLI fixture', '--email' => 'cli@example.test', '--reason' => 'Setup', '--accountability' => 'test-operations', '--no-interaction' => true]);
        $this->assertSame(0, $result);
        $output = Artisan::output();
        $this->assertStringNotContainsString('cli@example.test', $output);
        $this->assertStringNotContainsString('token', $output);
        $data = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['operator_id', 'replayed', 'publication_status'], array_keys($data));
        $operator = PlatformOperator::find($data['operator_id']);
        [$link,$plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'activation'));
        DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'activation'));
        Mail::fake();
        (new SendPlatformAccessLink($link->id, $link->generation, $plain))->handle();
        Mail::assertNothingSent();
    }

    public function test_log_mailer_never_receives_platform_link_and_failure_has_no_transport_context(): void
    {
        $operator = $this->operator(false);
        $operator->update(['verified_at' => null, 'password' => null]);
        [$link,$plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'activation'));
        config(['mail.default' => 'log', 'platform.frontend_url' => 'https://example.test']);
        Mail::fake();
        try {
            (new SendPlatformAccessLink($link->id, $link->generation, $plain))->handle();
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Platform access publication failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString($plain, $e->getMessage());
        }
        Mail::assertNothingSent();
        $this->assertSame('failed', $link->fresh()->publication_status);
    }

    public function test_mail_origin_rejects_embedded_credentials_without_sending(): void
    {
        $operator = $this->operator(false);
        $operator->update(['verified_at' => null, 'password' => null]);
        [$link, $plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'activation'));
        config(['mail.default' => 'array', 'platform.frontend_url' => 'https://:credential-fixture@example.test']);
        Mail::fake();
        try {
            (new SendPlatformAccessLink($link->id, $link->generation, $plain))->handle();
            $this->fail('Unsafe origin was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Platform access publication failed.', $e->getMessage());
        }
        Mail::assertNothingSent();
    }
}
