<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\PlatformAuthChallenge;
use App\Platform\Models\PlatformOperator;
use App\Platform\Models\PlatformRecoveryCode;
use App\Platform\PlatformException;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAuthentication;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;

class PlatformAuthenticationTest extends PlatformTestCase
{
    private string $prefix = '/api/v1/platform/auth/';

    private function login(PlatformOperator $operator): array
    {
        return $this->postJson($this->prefix.'login', ['email' => $operator->email, 'password' => 'Valid-test-password-123'])->assertStatus(202)->json('data');
    }

    public function test_principals_are_isolated_and_password_only_does_not_authorize(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create(['institution_id' => $institution->id]);
        $userToken = $user->createToken('test')->plainTextToken;
        $this->withToken($userToken)->getJson('/api/v1/platform/plans')->assertUnauthorized();
        $this->withToken($userToken)->postJson($this->prefix.'login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $operator = $this->operator(false);
        $challenge = $this->login($operator);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($challenge['challenge_token'])->getJson('/api/v1/platform/plans')->assertUnauthorized();
        $plain = $this->token($this->operator());
        foreach (['/api/v1/user/profile', '/api/v1/institution/users', '/api/v1/institution/tree-directory', '/api/v1/documents/'.Str::uuid().'/history', '/dashboard'] as $path) {
            $this->withToken($plain)->getJson($path)->assertForbidden();
        }
        $this->withToken($plain)->getJson('/api/v1/platform/plans')->assertOk();
    }

    public function test_enrollment_confirmation_and_replay_safe_login(): void
    {
        $operator = $this->operator(false);
        $challenge = $this->login($operator);
        $enrollment = $this->postJson($this->prefix.'mfa/enrollment', $this->proof($challenge))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $secret = $enrollment['manual_secret'];
        $this->assertNotSame($secret, DB::table('platform_operators')->where('id', $operator->id)->value('pending_totp_secret'));
        $this->postJson($this->prefix.'mfa/confirmation', $this->proof($challenge) + ['code' => $this->code($secret)])->assertOk()->assertJsonCount(10, 'data.recovery_codes');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson($this->prefix.'mfa/confirmation', $this->proof($challenge) + ['code' => $this->code($secret)])->assertStatus(400);
        $challenge = $this->login($operator);
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['code' => $this->code($secret)])->assertStatus(400);
        $this->travel(31)->seconds();
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['code' => $this->code($secret)])->assertOk()->assertJsonStructure(['data' => ['token', 'operator', 'expires_at']]);
        $this->travel(61)->seconds();
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['code' => $this->code($secret)])->assertStatus(400);
    }

    private function proof(array $challenge): array
    {
        return array_intersect_key($challenge, array_flip(['challenge_id', 'challenge_token']));
    }

    public function test_expired_invalid_and_exhausted_challenges_commit_attempts(): void
    {
        $operator = $this->operator();
        $auth = app(PlatformAuthentication::class);
        $challenge = $auth->login($operator->email, 'Valid-test-password-123');
        for ($i = 0; $i < 5; $i++) {
            try {
                $auth->prove($this->proof($challenge) + ['code' => 'invalid'], 'challenge');
                $this->fail();
            } catch (PlatformException $e) {
                $this->assertSame('MFA_CHALLENGE_INVALID', $e->errorCode);
            }
        }
        $this->assertSame(5, PlatformAuthChallenge::find($challenge['challenge_id'])->attempts);
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['code' => $this->code($operator->totp_secret)])->assertStatus(400);
        $challenge = $auth->login($operator->email, 'Valid-test-password-123');
        $this->travel(6)->minutes();
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['code' => $this->code($operator->totp_secret)])->assertStatus(400);
    }

    public function test_recovery_codes_are_single_use_and_require_reenrollment(): void
    {
        $operator = $this->operator();
        $oldToken = $this->token($operator);
        $code = Str::random(32);
        PlatformRecoveryCode::create(['operator_id' => $operator->id, 'code_hash' => Hash::make($code)]);
        $challenge = $this->login($operator);
        $next = $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['recovery_code' => $code])->assertStatus(202)->assertJsonMissingPath('data.token')->json('data');
        $this->assertNotNull(PlatformRecoveryCode::first()->consumed_at);
        $this->assertNull($operator->fresh()->mfa_confirmed_at);
        $this->withToken($oldToken)->getJson('/api/v1/platform/plans')->assertUnauthorized();
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['recovery_code' => $code])->assertStatus(400);
        $this->postJson($this->prefix.'mfa/enrollment', $this->proof($next))->assertOk();
    }

    public function test_password_reset_preserves_mfa_and_revokes_credentials(): void
    {
        Bus::fake();
        $operator = $this->operator();
        $token = $this->token($operator);
        $auth = app(PlatformAuthentication::class);
        $challenge = $auth->login($operator->email, 'Valid-test-password-123');
        [$link,$plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'password_reset'));
        $this->postJson($this->prefix.'password-reset', ['email' => $operator->email, 'token' => $plain, 'password' => 'New-test-password-456', 'password_confirmation' => 'New-test-password-456'])->assertOk();
        $this->assertSame($operator->totp_secret, $operator->fresh()->totp_secret);
        $this->assertNotNull($operator->fresh()->mfa_confirmed_at);
        $this->withToken($token)->getJson($this->prefix.'profile')->assertUnauthorized();
        $this->postJson($this->prefix.'mfa/challenge', $this->proof($challenge) + ['code' => $this->code($operator->totp_secret)])->assertStatus(400);
        $this->postJson($this->prefix.'password-reset', ['email' => $operator->email, 'token' => $plain, 'password' => 'Another-test-password-789', 'password_confirmation' => 'Another-test-password-789'])->assertStatus(400);
    }

    public function test_authenticated_password_change_revokes_and_mfa_token_metadata_is_required(): void
    {
        $operator = $this->operator();
        $unverified = $operator->createToken('without-proof', ['platform'], now()->addHour())->plainTextToken;
        $this->withToken($unverified)->getJson('/api/v1/platform/plans')->assertForbidden();
        $token = $this->token($operator);
        $this->withToken($token)->patchJson($this->prefix.'password', ['current_password' => 'Valid-test-password-123', 'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123'])->assertOk();
        $this->withToken($token)->getJson($this->prefix.'profile')->assertUnauthorized();
        $this->assertNotNull($operator->fresh()->mfa_confirmed_at);
    }

    public function test_recovery_is_generic_and_publication_is_encrypted_and_failure_safe(): void
    {
        Bus::fake();
        $operator = $this->operator();
        $response = $this->postJson($this->prefix.'password-recovery', ['email' => $operator->email])->assertStatus(202)->json();
        $this->assertSame($response, $this->postJson($this->prefix.'password-recovery', ['email' => 'unknown@example.test'])->assertStatus(202)->json());
        Bus::assertDispatched(SendPlatformAccessLink::class);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendPlatformAccessLink((string) Str::uuid(), 1, 'fixture'));
        [$link,$plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'password_reset'));
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('Sensitive transport failure'));
        $this->assertSame('failed', app(PlatformAccessLinks::class)->publish($link, $plain));
        $this->assertDatabaseHas('platform_access_links', ['id' => $link->id, 'publication_status' => 'failed']);
        $history = AdministrativeEvent::all()->toJson();
        $this->assertStringNotContainsString($plain, $history);
        $this->assertStringNotContainsString($operator->totp_secret, $history);
    }

    public function test_audit_failure_rolls_back_mfa_token_and_recovery_consumption(): void
    {
        $operator = $this->operator();
        $auth = app(PlatformAuthentication::class);
        $challenge = $auth->login($operator->email, 'Valid-test-password-123');
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(PlatformAuthentication::class)->prove($this->proof($challenge) + ['code' => $this->code($operator->totp_secret)], 'challenge');
            $this->fail();
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PlatformAuthChallenge::first()->consumed_at);
        $this->assertSame(-1, $operator->fresh()->last_totp_step);
    }

    public function test_strict_inputs_and_rate_limiting(): void
    {
        $operator = $this->operator();
        $this->postJson($this->prefix.'login', ['email' => $operator->email, 'password' => 'Valid-test-password-123', 'active' => true])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->prefix.'login', ['email' => 'limited@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson($this->prefix.'login', ['email' => 'limited@example.test', 'password' => 'wrong'])->assertStatus(429)->assertHeader('Retry-After');
    }
}
