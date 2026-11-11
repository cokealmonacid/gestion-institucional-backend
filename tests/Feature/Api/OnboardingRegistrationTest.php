<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class OnboardingRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $override = []): array
    {
        return array_replace([
            'name' => ' New User ', 'email' => ' NEW@example.test ',
            'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        ], $override);
    }

    public function test_register_creates_only_a_pending_account_and_expiring_limited_token_without_email(): void
    {
        Mail::fake();
        Notification::fake();
        Event::fake([Registered::class]);
        $this->freezeTime();

        $response = $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.delivery_status', 'disabled')
            ->assertJsonPath('data.user.email', 'new@example.test')
            ->assertJsonPath('data.user.name', 'New User')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.user.institution', null)
            ->assertJsonPath('data.user.roles', [])
            ->assertJsonPath('data.user.abilities', [])
            ->assertJsonPath('data.onboarding.state', 'verify_email');
        $user = User::sole();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertDatabaseCount('institutions', 0);
        $this->assertDatabaseCount('role_user', 0);
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame(['onboarding'], $token->abilities);
        $this->assertSame(now()->addHours(24)->timestamp, $token->expires_at->timestamp);
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertArrayNotHasKey('verification_url', $response->json('data'));
        Event::assertNotDispatched(Registered::class);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_registration_validates_fields_and_rejects_privilege_injection(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration([
            'name' => ' ', 'password_confirmation' => 'different',
            'institution_id' => 'foreign', 'email_verified_at' => now()->toISOString(), 'active' => false,
        ]))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_duplicate_and_soft_deleted_emails_cannot_be_registered_or_restored(): void
    {
        $user = User::factory()->create(['email' => 'new@example.test']);
        $this->postJson('/api/v1/auth/register', $this->registration())->assertUnprocessable()
            ->assertJsonPath('error.fields.email.0', 'El correo ya está registrado.');
        $user->delete();
        $this->postJson('/api/v1/auth/register', $this->registration())->assertUnprocessable();
        $this->assertSoftDeleted($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pending_login_and_profile_resume_after_manual_database_verification(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'secret-password']);
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk()->assertJsonPath('data.onboarding.state', 'verify_email');
        $token = $response->json('data.token');
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/user/profile')->assertOk()
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonPath('data.onboarding.state', 'create_institution')
            ->assertJsonPath('data.user.abilities', []);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_pending_session_expires_after_24_hours(): void
    {
        $this->freezeTime();
        $response = $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated();
        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        Auth::forgetGuards();
        $this->withToken($response->json('data.token'))->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_limited_token_cannot_gain_access_even_if_user_is_later_assigned_an_institution(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('onboarding', ['onboarding'], now()->addDay())->plainTextToken;
        $user->forceFill(['email_verified_at' => now(), 'institution_id' => Institution::factory()->create()->id])->save();
        $user->roles()->attach(Rol::create(['type' => RoleType::Admin]));
        foreach ([['GET', '/api/v1/institutions'], ['GET', '/api/v1/institution/tree-directory'],
            ['GET', '/api/v1/institution/users'], ['GET', '/api/v1/institution/tag'],
            ['GET', '/api/v1/nodes'], ['GET', '/api/v1/institution/documents'],
            ['GET', '/api/v1/user/search?q=test'], ['POST', '/api/v1/institution/document/unknown/tags'],
            ['POST', '/api/v1/documents/unknown/versions']] as [$method, $uri]) {
            Auth::forgetGuards();
            $this->withToken($token)->json($method, $uri)->assertForbidden()
                ->assertJsonPath('error.code', 'ONBOARDING_REQUIRED');
        }
    }

    public function test_unverified_web_session_cannot_use_institutional_api_but_blade_dashboard_still_works(): void
    {
        $user = User::factory()->unverified()->for(Institution::factory())->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->getJson('/api/v1/institution/tree-directory')->assertForbidden()
            ->assertJsonPath('error.code', 'ONBOARDING_REQUIRED');
    }

    public function test_registration_and_login_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
            $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/api/v1/auth/login', [])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_no_institution_can_be_enumerated_anonymously(): void
    {
        $this->getJson('/api/v1/institutions')->assertUnauthorized();
        $institution = Institution::factory()->create();
        Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $this->withToken($user->createToken('full')->plainTextToken)->getJson('/api/v1/institutions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $institution->id);
    }

    public function test_malformed_email_types_return_validation_errors_instead_of_server_errors(): void
    {
        foreach (['register', 'login', 'forget-password', 'reset-password-confirm'] as $route) {
            $this->postJson('/api/v1/auth/'.$route, $this->registration(['email' => ['not-a-string']]))
                ->assertUnprocessable();
        }
    }

    public function test_registration_does_not_duplicate_a_legacy_mixed_case_email(): void
    {
        User::factory()->create(['email' => 'NEW@example.test']);
        $this->postJson('/api/v1/auth/register', $this->registration())->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_inactive_users_cannot_login_verify_or_resend(): void
    {
        $user = User::factory()->unverified()->inactive()->create(['password' => 'secret-password']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertForbidden()->assertJsonPath('error.code', 'AUTH_ACCOUNT_INACTIVE');
        $token = $user->createToken('existing', ['onboarding'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/email/verify')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
