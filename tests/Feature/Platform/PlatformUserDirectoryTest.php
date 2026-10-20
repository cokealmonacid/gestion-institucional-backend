<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use RuntimeException;

class PlatformUserDirectoryTest extends PlatformTestCase
{
    public function test_directory_filters_paginates_and_reports_actual_roles_and_invitation_records(): void
    {
        $institution = Institution::factory()->create();
        $other = Institution::factory()->create();
        $admin = Rol::firstOrCreate(['type' => RoleType::Admin]);
        $editor = Rol::firstOrCreate(['type' => RoleType::Editor]);
        $user = User::factory()->for($institution)->create(['name' => 'A multi role', 'active' => false]);
        $user->roles()->attach([$admin->id, $editor->id]);
        UserInvitation::create(['user_id' => $user->id, 'generation' => 1, 'delivery_status' => 'failed', 'expires_at' => now()->addHour(), 'token_hash' => 'not-exposed']);
        $noRole = User::factory()->for($institution)->create(['name' => 'B no role', 'active' => true]);
        User::factory()->for($other)->create();
        User::factory()->for($institution)->create()->delete();
        $this->withToken($this->token($this->operator()));
        $base = '/api/v1/platform/users';
        $row = $this->getJson($base.'?institution_id='.$institution->id.'&per_page=1')->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)->json('data.items.0');
        $this->assertSame(['admin', 'editor'], $row['roles']);
        $this->assertSame('failed', $row['invitation']['delivery_status']);
        $this->assertNull($row['invitation']['consumed_at']);
        $this->assertSame(['user_id', 'name', 'email', 'institution_id', 'institution_name', 'roles', 'active', 'email_verified', 'access_revision', 'invitation'], array_keys($row));
        $this->assertSame(['delivery_status', 'expires_at', 'revoked_at', 'consumed_at'], array_keys($row['invitation']));
        $this->getJson($base.'?institution_id='.$institution->id.'&per_page=1&page=2')->assertJsonPath('data.items.0.user_id', $noRole->id)->assertJsonPath('data.items.0.roles', [])->assertJsonPath('data.items.0.invitation', null);
        $this->getJson($base.'?q='.urlencode($user->email).'&role=editor&active=false')->assertJsonPath('data.total', 1);
        $this->getJson($base.'?role=reader')->assertJsonPath('data.total', 0);
        $this->getJson($base.'?institution_id='.$institution->id.'&role=none&active=true')->assertJsonPath('data.total', 1);
        $this->getJson($base.'?user_id='.$noRole->id.'&institution_id='.$institution->id)->assertJsonPath('data.items.0.user_id', $noRole->id);
        $this->getJson($base.'?user_id='.$noRole->id.'&institution_id='.$other->id)->assertJsonPath('data.total', 0);
        foreach (['per_page=101', 'role=owner', 'active=invalid', 'password=forbidden', 'institution_id=invalid'] as $query) {
            $this->getJson($base.'?'.$query)->assertUnprocessable();
        }
        Gate::define('platform.administrator_access', fn () => false);
        $this->getJson($base)->assertForbidden();
    }

    public function test_directory_rejects_institutional_and_password_only_identities(): void
    {
        $user = User::factory()->create();
        $this->getJson('/api/v1/platform/users')->assertUnauthorized();
        $this->withToken($user->createToken('fixture', ['*'])->plainTextToken)->getJson('/api/v1/platform/users')->assertUnauthorized();
        $operator = $this->operator(false);
        $challenge = $this->postJson('/api/v1/platform/auth/login', ['email' => $operator->email, 'password' => 'Valid-test-password-123'])->json('data.challenge_token');
        $this->withToken($challenge)->getJson('/api/v1/platform/users')->assertUnauthorized();
    }

    public function test_email_conflict_is_definitive_but_platform_identity_alone_does_not_conflict(): void
    {
        Bus::fake();
        Mail::fake();
        Rol::firstOrCreate(['type' => RoleType::Admin]);
        $operator = $this->operator();
        $institution = Institution::factory()->create();
        $existing = User::factory()->create(['email' => 'existing@example.test', 'active' => false]);
        $existing->delete();
        $this->withToken($this->token($operator));
        $key = (string) Str::uuid();
        $path = '/api/v1/platform/institutions/'.$institution->id.'/administrator-invitations';
        $this->withHeader('Idempotency-Key', $key)->postJson($path, ['name' => 'Synthetic', 'email' => $existing->email, 'reason' => 'Fixture'])->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_UNAVAILABLE');
        $this->getJson('/api/v1/platform/operations/'.$key.'?operation_type=administrator_invitation')->assertNotFound();
        $this->assertDatabaseCount('platform_operations', 0);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($path, ['name' => 'Synthetic', 'email' => $operator->email, 'reason' => 'Fixture'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => $operator->email, 'institution_id' => $institution->id]);
        Mail::assertNothingSent();
    }

    public function test_failed_invitation_publication_preserves_account_operation_list_and_quota(): void
    {
        Mail::fake();
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Synthetic unavailable queue'));
        Rol::firstOrCreate(['type' => RoleType::Admin]);
        $institution = Institution::factory()->create();
        $this->withToken($this->token($this->operator()));
        $key = (string) Str::uuid();
        $result = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/platform/institutions/'.$institution->id.'/administrator-invitations', ['name' => 'Synthetic', 'email' => 'publication-fixture@example.test', 'reason' => 'Fixture'])
            ->assertCreated()->assertJsonPath('data.publication_status', 'failed')->json('data');
        $this->getJson('/api/v1/platform/operations/'.$key.'?operation_type=administrator_invitation')->assertOk()->assertJsonPath('data.result.administrator_id', $result['administrator_id']);
        $this->getJson('/api/v1/platform/institutions/'.$institution->id.'/administrators')->assertJsonPath('data.items.0.user_id', $result['administrator_id']);
        $this->getJson('/api/v1/platform/institutions/'.$institution->id.'/quotas')->assertJsonPath('data.roles.admin.used', 1);
        $this->getJson('/api/v1/platform/institutions/'.$institution->id.'/administrators/'.$result['administrator_id'].'/invitation')->assertJsonPath('data.delivery_status', 'failed');
        Mail::assertNothingSent();
    }
}
