<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class OnboardingPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_account_cannot_receive_or_use_password_recovery(): void
    {
        Mail::fake();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $user->email])->assertStatus(202)
            ->assertExactJson(['success' => true, 'data' => null, 'message' => 'If the account is eligible, recovery instructions will be sent.']);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNull($stored);
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $user->email])->assertStatus(429);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_pending_account_cannot_reset_password(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'old-password']);
        $user->createToken('pending', ['onboarding']);
        $token = Password::broker()->createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/v1/auth/password-reset', $payload)->assertStatus(400);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_reset_rejects_expired_tokens_and_another_accounts_token(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $other = User::factory()->create(['password' => 'other-password']);
        $token = Password::broker()->createToken($user);
        $payload = ['email' => $other->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/v1/auth/password-reset', $payload)->assertStatus(400);
        $this->travel(61)->minutes();
        $payload['email'] = $user->email;
        $this->postJson('/api/v1/auth/password-reset', $payload)->assertStatus(400);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
