<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\EmailVerificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OnboardingEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function parameters(User $user): array
    {
        parse_str(parse_url(app(EmailVerificationLink::class)->forUser($user), PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    public function test_signed_verification_is_actor_bound_idempotent_and_does_not_send_email(): void
    {
        Mail::fake();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $params = $this->parameters($user);
        $this->withToken($other->createToken('pending', ['onboarding'])->plainTextToken)
            ->postJson('/api/v1/auth/email/verify', $params)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
        Auth::forgetGuards();
        $token = $user->createToken('pending', ['onboarding'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/email/verify', $params)->assertOk()
            ->assertJsonPath('data.onboarding.state', 'create_institution');
        $verifiedAt = $user->fresh()->email_verified_at;
        $this->travel(1)->minutes();
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/email/verify', $params)->assertOk();
        $this->assertTrue($user->fresh()->email_verified_at->equalTo($verifiedAt));
        $this->assertSame(['onboarding'], $user->tokens()->latest('id')->first()->abilities);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_invalid_expired_or_email_mismatched_signatures_do_not_verify(): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $params = $this->parameters($user);
        $this->assertSame(now()->addMinutes(60)->timestamp, (int) $params['expires']);
        $token = $user->createToken('pending', ['onboarding'])->plainTextToken;
        foreach (['signature' => str_repeat('0', 64), 'hash' => str_repeat('0', 40), 'expires' => now()->addDay()->timestamp] as $key => $value) {
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/v1/auth/email/verify', array_replace($params, [$key => $value]))->assertForbidden();
        }
        $user->update(['email' => 'changed@example.test']);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/email/verify', $params)->assertForbidden();
        $params = $this->parameters($user);
        $this->travel(61)->minutes();
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/email/verify', $params)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_is_disabled_without_a_link_and_enforces_both_account_limits(): void
    {
        Mail::fake();
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('pending', ['onboarding'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202)
            ->assertExactJson(['success' => true, 'data' => ['delivery_status' => 'disabled'], 'message' => 'Email delivery is not available.']);
        $otherToken = $user->createToken('another', ['onboarding'])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds();
            $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(1)->hours();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);
        $this->assertNull($user->fresh()->email_verified_at);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_verification_requires_authentication_and_only_accepts_post(): void
    {
        $this->postJson('/api/v1/auth/email/verify')->assertUnauthorized();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
        $user = User::factory()->unverified()->create();
        $this->getJson(app(EmailVerificationLink::class)->forUser($user))->assertStatus(405);
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
