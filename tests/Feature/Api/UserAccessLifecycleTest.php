<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Jobs\SendPasswordRecovery;
use App\Jobs\SendUserInvitation;
use App\Mail\PasswordRecoveryMail;
use App\Mail\UserInvitationMail;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\ViewException;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

class UserAccessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
    }

    public function test_admin_creates_user_role_and_hashed_invitation_atomically_without_password(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Editor]);
        Queue::fake();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id,
            'name' => 'Invitada',
            'email' => 'invited@example.test',
            'role' => 'editor',
        ])->assertCreated()
            ->assertJsonPath('data.activation_status', 'pending_activation')
            ->assertJsonPath('data.delivery_status', 'pending');

        $user = User::query()->where('email', 'invited@example.test')->firstOrFail();
        $invitation = $user->invitation()->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->roles()->where('type', RoleType::Editor)->exists());
        $this->assertNotNull($invitation->token_hash);
        $this->assertNotSame($invitation->token_hash, Queue::pushed(SendUserInvitation::class)->first()->token);
        $this->assertInstanceOf(ShouldBeEncrypted::class, Queue::pushed(SendUserInvitation::class)->first());
        $this->assertSame($user->id, $response->json('data.user_id'));
    }

    public function test_creation_rolls_back_when_role_is_missing(): void
    {
        [$institution, $admin] = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id,
            'name' => 'No role',
            'email' => 'rollback@example.test',
            'role' => 'reader',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseCount('user_invitations', 0);
    }

    public function test_creation_rolls_back_user_and_role_when_invitation_persistence_fails(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        DB::unprepared("CREATE TRIGGER fail_invitation BEFORE INSERT ON user_invitations BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Rollback',
            'email' => 'atomic@example.test', 'role' => 'reader',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('users', ['email' => 'atomic@example.test']);
        $this->assertDatabaseCount('user_invitations', 0);
    }

    public function test_queue_publication_failure_keeps_account_localizable_and_resendable(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Sanctum::actingAs($admin);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        $response = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id,
            'name' => 'Queued later',
            'email' => 'queue-failure@example.test',
            'role' => 'reader',
        ])->assertCreated()->assertJsonPath('data.delivery_status', 'failed');

        $this->assertDatabaseHas('users', ['id' => $response->json('data.user_id')]);
        $this->assertDatabaseHas('user_invitations', [
            'user_id' => $response->json('data.user_id'),
            'delivery_status' => 'failed',
        ]);
    }

    public function test_activation_is_single_use_sets_password_and_does_not_sign_in(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Pending', 'email' => 'pending@example.test', 'role' => 'reader',
        ])->assertCreated();
        $job = Queue::pushed(SendUserInvitation::class)->first();
        auth()->forgetGuards();

        $payload = [
            'email' => 'pending@example.test', 'token' => $job->token,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ];
        $this->postJson('/api/v1/auth/activation', $payload)->assertOk();

        $user = User::query()->where('email', 'pending@example.test')->firstOrFail();
        $invitation = $user->invitation()->firstOrFail();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($invitation->token_hash);
        $this->assertNotNull($invitation->consumed_at);
        $this->assertGuest();
        $this->postJson('/api/v1/auth/activation', $payload)->assertBadRequest();
    }

    public function test_reissued_invitation_invalidates_previous_activation_token(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Pending', 'email' => 'replace@example.test', 'role' => 'reader',
        ])->assertCreated();
        $oldToken = Queue::pushed(SendUserInvitation::class)->first()->token;
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/institution/users/'.$created->json('data.user_id').'/invitation')->assertAccepted();
        $newToken = Queue::pushed(SendUserInvitation::class)->last()->token;

        $this->postJson('/api/v1/auth/activation', [
            'email' => 'replace@example.test', 'token' => $oldToken,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertBadRequest();
        $this->postJson('/api/v1/auth/activation', [
            'email' => 'replace@example.test', 'token' => $newToken,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
    }

    public function test_expired_and_invalid_invitation_tokens_are_rejected_without_activation(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Expires', 'email' => 'expired-invite@example.test', 'role' => 'reader',
        ])->assertCreated();
        $token = Queue::pushed(SendUserInvitation::class)->first()->token;
        auth()->forgetGuards();

        foreach (['invalid-token', $token] as $candidate) {
            if ($candidate === $token) {
                UserInvitation::query()->update(['expires_at' => now()->subSecond()]);
            }
            $this->postJson('/api/v1/auth/activation', [
                'email' => 'expired-invite@example.test', 'token' => $candidate,
                'password' => 'new-password', 'password_confirmation' => 'new-password',
            ])->assertBadRequest();
        }

        $user = User::query()->where('email', 'expired-invite@example.test')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->invitation->consumed_at);
    }

    public function test_late_invitation_job_does_not_send_or_overwrite_current_generation(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Pending', 'email' => 'late@example.test', 'role' => 'reader',
        ])->assertCreated();
        $old = Queue::pushed(SendUserInvitation::class)->first();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/institution/users/'.$created->json('data.user_id').'/invitation')->assertAccepted();
        $jobs = Queue::pushed(SendUserInvitation::class);
        $current = $jobs->last();
        Mail::fake();

        $old->handle();
        Mail::assertNothingSent();
        $current->handle();
        Mail::assertSent(UserInvitationMail::class, 1);
        $this->assertDatabaseHas('user_invitations', ['user_id' => $created->json('data.user_id'), 'generation' => 2, 'delivery_status' => 'accepted']);
    }

    public function test_log_mailer_is_rejected_and_transport_failure_is_recorded_without_token(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Safe mail', 'email' => 'safe-mail@example.test', 'role' => 'reader',
        ])->assertCreated();
        $job = Queue::pushed(SendUserInvitation::class)->first();
        config(['mail.default' => 'log']);
        Log::shouldReceive('warning')->once()->with('Invitation email failed.', [
            'exception_class' => RuntimeException::class,
            'failure_category' => 'mailer_policy',
            'failure_code' => 0,
        ]);

        try {
            $job->handle();
            $this->fail('The log mailer must not handle access links.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The invitation email transport failed.', $exception->getMessage());
        }

        $invitation = UserInvitation::query()->where('user_id', $created->json('data.user_id'))->firstOrFail();
        $this->assertSame('failed', $invitation->delivery_status);
        $this->assertNotSame($job->token, $invitation->token_hash);
    }

    #[DataProvider('invitationMailFailures')]
    public function test_invitation_failure_diagnostics_do_not_expose_messages_or_tokens(\Throwable $failure, string $category, ?string $reason = null): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Diagnostic test',
            'email' => 'diagnostic@example.test', 'role' => 'reader',
        ])->assertCreated();
        $job = Queue::pushed(SendUserInvitation::class)->first();
        Mail::shouldReceive('to')->once()->with('diagnostic@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow($failure);
        Log::shouldReceive('warning')->once()->with('Invitation email failed.', [
            'exception_class' => get_class($failure),
            'failure_category' => $category,
            'failure_code' => $failure->getCode(),
            ...($reason === null ? [] : ['smtp_rejection_reason' => $reason]),
        ]);

        try {
            $job->handle();
            $this->fail('The mail failure must propagate safely.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The invitation email transport failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($job->token, (string) $exception);
            $this->assertStringNotContainsString('sensitive-placeholder', (string) $exception);
        }
        $this->assertDatabaseHas('user_invitations', [
            'user_id' => $created->json('data.user_id'), 'delivery_status' => 'failed',
        ]);
    }

    public static function invitationMailFailures(): array
    {
        return [
            'transport' => [new TransportException('sensitive-placeholder'), 'mail_transport'],
            'rendering' => [new ViewException('sensitive-placeholder'), 'mail_rendering'],
            'test recipient restriction' => [new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 You can only send testing emails to your own email address (owner@example.test). To send emails to other recipients, please verify a domain. sensitive-placeholder https://example.test/activate?token=sensitive-placeholder".', 550), 'mail_transport', 'test_recipient_restriction'],
            'unverified sender domain' => [new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 The example.test domain is not verified. sensitive-placeholder".', 550), 'mail_transport', 'sender_domain_rejection'],
            'sender rejected' => [new UnexpectedResponseException('Expected response code "250" but got code "553", with message "553 Sender address rejected: sender@example.test sensitive-placeholder".', 553), 'mail_transport', 'sender_domain_rejection'],
            'other rejection' => [new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 Unknown user recipient@example.test sensitive-placeholder".', 550), 'mail_transport', 'other_rejection'],
            'unrecognized format' => [new UnexpectedResponseException('sensitive-placeholder', 550), 'mail_transport', 'other_rejection'],
            'authentication excluded' => [new UnexpectedResponseException('Expected response code "235" but got code "535", with message "535 sensitive-placeholder".', 535), 'mail_transport'],
        ];
    }

    public function test_recovery_is_generic_and_only_eligible_user_gets_a_job(): void
    {
        [$institution] = $this->admin();
        $eligible = User::factory()->for($institution)->create(['email' => 'eligible@example.test']);
        User::factory()->unverified()->for($institution)->create(['email' => 'pending-recovery@example.test']);
        Queue::fake();

        foreach (['missing@example.test', 'pending-recovery@example.test', $eligible->email] as $email) {
            $this->postJson('/api/v1/auth/password-recovery', ['email' => $email])
                ->assertAccepted()->assertExactJson([
                    'success' => true, 'data' => null,
                    'message' => 'If the account is eligible, recovery instructions will be sent.',
                ]);
        }

        Queue::assertPushed(SendPasswordRecovery::class, 1);
        $this->assertInstanceOf(ShouldBeEncrypted::class, Queue::pushed(SendPasswordRecovery::class)->first());
    }

    public function test_old_recovery_job_does_not_send_after_a_new_token_is_issued(): void
    {
        [$institution] = $this->admin();
        $user = User::factory()->for($institution)->create(['email' => 'late-recovery@example.test']);
        Queue::fake();
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $user->email])->assertAccepted();
        $old = Queue::pushed(SendPasswordRecovery::class)->first();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $user->email])->assertAccepted();
        $current = Queue::pushed(SendPasswordRecovery::class)->last();
        Mail::fake();

        $old->handle();
        Mail::assertNothingSent();
        $current->handle();
        Mail::assertSent(PasswordRecoveryMail::class, 1);
    }

    public function test_recovery_queue_publication_failure_removes_unmailed_token(): void
    {
        [$institution] = $this->admin();
        $user = User::factory()->for($institution)->create(['email' => 'recovery-queue@example.test']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        $this->postJson('/api/v1/auth/password-recovery', ['email' => $user->email])->assertAccepted();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_recovery_resets_password_without_verification_change_and_revokes_all_tokens(): void
    {
        [$institution] = $this->admin();
        $user = User::factory()->for($institution)->create(['email' => 'recover@example.test']);
        $verifiedAt = $user->email_verified_at;
        $user->createToken('one');
        $user->createToken('two');
        Queue::fake();
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $user->email])->assertAccepted();
        $job = Queue::pushed(SendPasswordRecovery::class)->first();

        $this->postJson('/api/v1/auth/password-reset', [
            'email' => $user->email, 'token' => $job->token,
            'password' => 'recovered-password', 'password_confirmation' => 'recovered-password',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('recovered-password', $user->password));
        $this->assertEquals($verifiedAt, $user->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse(Password::broker()->tokenExists($user, $job->token));
    }

    public function test_expired_and_invalid_recovery_tokens_are_rejected_without_changing_password(): void
    {
        [$institution] = $this->admin();
        $user = User::factory()->for($institution)->create(['email' => 'expired-recovery@example.test', 'password' => 'old-password']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/password-reset', [
            'email' => $user->email, 'token' => 'invalid-token',
            'password' => 'invalid-change', 'password_confirmation' => 'invalid-change',
        ])->assertBadRequest();

        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1),
        ]);
        $this->postJson('/api/v1/auth/password-reset', [
            'email' => $user->email, 'token' => $token,
            'password' => 'expired-change', 'password_confirmation' => 'expired-change',
        ])->assertBadRequest();

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_change_revokes_sessions_and_pending_recovery(): void
    {
        [$institution] = $this->admin();
        $user = User::factory()->for($institution)->create(['password' => 'old-password']);
        $current = $user->createToken('current');
        $user->createToken('other');
        Password::broker()->createToken($user);

        $this->withToken($current->plainTextToken)->patchJson('/api/v1/user/update-password', [
            'current_password' => 'old-password',
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
    }

    public function test_inactive_accounts_and_institutions_cannot_activate_or_recover(): void
    {
        [$institution, $admin] = $this->admin();
        Rol::create(['type' => RoleType::Reader]);
        Queue::fake();
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Disabled', 'email' => 'disabled@example.test', 'role' => 'reader',
        ])->assertCreated();
        $token = Queue::pushed(SendUserInvitation::class)->first()->token;
        User::query()->whereKey($created->json('data.user_id'))->update(['active' => false]);
        auth()->forgetGuards();

        $this->postJson('/api/v1/auth/activation', [
            'email' => 'disabled@example.test', 'token' => $token,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertBadRequest();

        $active = User::factory()->for($institution)->create(['email' => 'institution-disabled@example.test']);
        $institution->update(['status' => false]);
        Queue::fake();
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $active->email])->assertAccepted();
        Queue::assertNothingPushed();
    }

    public function test_invitation_operations_enforce_admin_and_institution_isolation(): void
    {
        [$institution] = $this->admin();
        $editor = User::factory()->for($institution)->create();
        $editor->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Editor]));
        $foreignInstitution = Institution::factory()->create(['status' => true]);
        $foreign = User::factory()->unverified()->for($foreignInstitution)->create();
        Sanctum::actingAs($editor);

        $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id, 'name' => 'Forbidden', 'email' => 'forbidden@example.test', 'role' => 'reader',
        ])->assertForbidden();
        $this->getJson('/api/v1/institution/users/'.$foreign->id.'/invitation')->assertForbidden();
    }

    public function test_passwords_reject_unknown_properties_and_more_than_72_utf8_bytes(): void
    {
        $password = str_repeat('á', 37);

        $this->postJson('/api/v1/auth/activation', [
            'email' => 'nobody@example.test', 'token' => 'token',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson('/api/v1/auth/password-reset', [
            'email' => 'nobody@example.test', 'token' => 'token',
            'password' => 'valid-password', 'password_confirmation' => 'valid-password', 'extra' => true,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_access_operations_apply_the_configured_rate_limits(): void
    {
        $login = ['email' => 'rate-login@example.test', 'password' => 'wrong'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $login)->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', $login)
            ->assertTooManyRequests()->assertJsonPath('error.code', 'AUTH_RATE_LIMITED');

        $this->postJson('/api/v1/auth/password-recovery', ['email' => 'rate-recovery@example.test'])->assertAccepted();
        $this->postJson('/api/v1/auth/password-recovery', ['email' => 'rate-recovery@example.test'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'AUTH_RATE_LIMITED');

        $activation = [
            'email' => 'rate-activation@example.test', 'token' => 'invalid',
            'password' => 'valid-password', 'password_confirmation' => 'valid-password',
        ];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/activation', $activation)->assertBadRequest();
        }
        $this->postJson('/api/v1/auth/activation', $activation)
            ->assertTooManyRequests()->assertJsonPath('error.code', 'AUTH_RATE_LIMITED');
    }

    public function test_web_bypass_routes_and_insecure_api_routes_are_disabled(): void
    {
        foreach ([
            ['GET', '/register'], ['POST', '/register'], ['GET', '/forgot-password'],
            ['POST', '/forgot-password'], ['POST', '/reset-password'], ['GET', '/profile'],
            ['POST', '/api/v1/auth/forget-password'], ['POST', '/api/v1/auth/reset-password-confirm'],
        ] as [$method, $path]) {
            $this->call($method, $path)->assertNotFound();
        }
    }

    /** @return array{Institution, User} */
    private function admin(): array
    {
        $institution = Institution::factory()->create(['status' => true]);
        $admin = User::factory()->for($institution)->create();
        $role = Rol::firstOrCreate(['type' => RoleType::Admin]);
        $admin->roles()->attach($role->id);

        return [$institution, $admin];
    }
}
