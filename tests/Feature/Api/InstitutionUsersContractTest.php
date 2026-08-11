<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class InstitutionUsersContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_four_operations_require_authentication(): void
    {
        foreach ([
            fn () => $this->getJson('/api/v1/institution/users'),
            fn () => $this->postJson('/api/v1/institution/users', []),
            fn () => $this->patchJson('/api/v1/institution/users', []),
            fn () => $this->patchJson('/api/v1/institution/users/role', []),
        ] as $call) {
            $call()
                ->assertUnauthorized()
                ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
        }
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        [$institution, $user] = $this->institutionUser();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/institution/users?institution_id={$institution->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_institution_id_must_match_the_authenticated_admins_own_institution(): void
    {
        [, $admin] = $this->institutionUser(admin: true);
        $otherInstitution = Institution::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/institution/users?institution_id={$otherInstitution->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_index_lists_only_users_of_the_given_institution(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $otherInstitution = Institution::factory()->create();
        $peer = User::factory()->for($institution)->create();
        User::factory()->for($otherInstitution)->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/institution/users?institution_id={$institution->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Users retrieved successfully.')
            ->assertJsonPath('data.total', 2);

        $items = collect($response->json('data.data'));
        $ids = $items->pluck('id')->all();
        $this->assertContains($admin->id, $ids);
        $this->assertContains($peer->id, $ids);
        $this->assertSame(['id', 'name', 'email', 'role', 'created_at', 'active'], array_keys($items->first()));

        $adminEntry = $items->firstWhere('id', $admin->id);
        $this->assertSame('admin', $adminEntry['role']);
        $this->assertTrue($adminEntry['active']);

        $peerEntry = $items->firstWhere('id', $peer->id);
        $this->assertNull($peerEntry['role']);
    }

    public function test_index_reports_inactive_users(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $inactive = User::factory()->inactive()->for($institution)->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/institution/users?institution_id={$institution->id}")
            ->assertOk();

        $entry = collect($response->json('data.data'))->firstWhere('id', $inactive->id);
        $this->assertFalse($entry['active']);
    }

    public function test_store_registers_a_user_with_the_given_role(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        Rol::create(['type' => RoleType::Editor]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'editor',
        ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [], 'message' => 'Account registered successfully.']);

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'institution_id' => $institution->id]);
        $created = User::whereEmail('ana@example.com')->first();
        $this->assertTrue($created->roles()->where('type', RoleType::Editor)->exists());
    }

    public function test_store_rejects_a_duplicate_email(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        Rol::create(['type' => RoleType::Editor]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'name' => 'Duplicate',
            'email' => $admin->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'editor',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('data.error.email.0', 'The email has already been taken.');
    }

    public function test_update_changes_the_targeted_users_profile(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $peer = User::factory()->for($institution)->create(['name' => 'Old name']);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
            'name' => 'New name',
        ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [], 'message' => 'Account updated successfully.']);

        $this->assertDatabaseHas('users', ['id' => $peer->id, 'name' => 'New name']);
    }

    public function test_update_rejects_a_user_from_another_institution(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $otherInstitution = Institution::factory()->create();
        $foreignUser = User::factory()->for($otherInstitution)->create();

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $foreignUser->email,
            'name' => 'New name',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('data.error.institution_id.0', 'User does not belong to this institution.');
    }

    public function test_update_role_swaps_the_targeted_users_role(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $editorRole = Rol::create(['type' => RoleType::Editor]);
        Rol::create(['type' => RoleType::Reader]);
        $peer = User::factory()->for($institution)->create();
        $peer->roles()->attach($editorRole->id);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/institution/users/role', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
            'role' => 'reader',
            'old_role' => 'editor',
        ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [], 'message' => 'Role updated successfully.']);

        $this->assertTrue($peer->fresh()->roles()->where('type', RoleType::Reader)->exists());
        $this->assertFalse($peer->fresh()->roles()->where('type', RoleType::Editor)->exists());
    }

    public function test_update_role_rejects_role_equal_to_old_role(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        Rol::create(['type' => RoleType::Editor]);
        $peer = User::factory()->for($institution)->create();

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/institution/users/role', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
            'role' => 'editor',
            'old_role' => 'editor',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.');
    }

    /** @return array{Institution, User} */
    private function institutionUser(bool $admin = false): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();

        if ($admin) {
            $role = Rol::create(['type' => RoleType::Admin]);
            $user->roles()->attach($role->id);
        }

        return [$institution, $user];
    }
}
