<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class InstitutionUsersContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operations_require_authentication(): void
    {
        foreach ([
            fn () => $this->getJson('/api/v1/institution/users'),
            fn () => $this->postJson('/api/v1/institution/users', []),
            fn () => $this->patchJson('/api/v1/institution/users', []),
            fn () => $this->deleteJson('/api/v1/institution/users', []),
        ] as $call) {
            $call()
                ->assertUnauthorized()
                ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
        }
    }

    public function test_editor_and_reader_cannot_list_or_manage_users(): void
    {
        foreach ([RoleType::Editor, RoleType::Reader] as $roleType) {
            [$institution, $user] = $this->institutionUser();
            $user->roles()->attach(Rol::firstOrCreate(['type' => $roleType]));
            $target = User::factory()->for($institution)->create();
            Sanctum::actingAs($user);

            foreach ([
                fn () => $this->getJson("/api/v1/institution/users?institution_id={$institution->id}"),
                fn () => $this->postJson('/api/v1/institution/users', ['institution_id' => $institution->id]),
                fn () => $this->patchJson('/api/v1/institution/users', [
                    'institution_id' => $institution->id,
                    'email' => $target->email,
                    'name' => 'Forbidden',
                ]),
                fn () => $this->deleteJson('/api/v1/institution/users', [
                    'institution_id' => $institution->id,
                    'email' => $target->email,
                ]),
            ] as $call) {
                $call()->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
            }

            $this->assertDatabaseHas('users', [
                'id' => $target->id,
                'name' => $target->name,
                'deleted_at' => null,
            ]);
        }
    }

    public function test_institution_id_must_match_the_authenticated_admins_own_institution(): void
    {
        [, $admin] = $this->institutionUser(admin: true);
        $otherInstitution = Institution::factory()->create();
        Sanctum::actingAs($admin);

        foreach ([$otherInstitution->id, (string) Str::uuid()] as $institutionId) {
            $this->getJson("/api/v1/institution/users?institution_id={$institutionId}")
                ->assertNotFound()
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Institution user not found.',
                ]);
        }
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

    public function test_request_institution_id_cannot_select_where_a_user_is_registered(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $foreignInstitution = Institution::factory()->create();
        Rol::create(['type' => RoleType::Editor]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/institution/users', [
            'institution_id' => $foreignInstitution->id,
            'name' => 'Foreign target',
            'email' => 'foreign-target@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'rol' => 'editor',
        ])->assertNotFound()->assertExactJson([
            'success' => false,
            'message' => 'Institution user not found.',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'foreign-target@example.com']);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'institution_id' => $institution->id]);
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

    public function test_update_cannot_distinguish_foreign_from_nonexistent_users(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $otherInstitution = Institution::factory()->create();
        $foreignUser = User::factory()->for($otherInstitution)->create();

        Sanctum::actingAs($admin);

        foreach ([$foreignUser->email, 'missing@example.com'] as $email) {
            $this->patchJson('/api/v1/institution/users', [
                'institution_id' => $institution->id,
                'email' => $email,
                'name' => 'New name',
            ])->assertNotFound()->assertExactJson([
                'success' => false,
                'message' => 'Institution user not found.',
            ]);
        }

        $this->assertDatabaseHas('users', ['id' => $foreignUser->id, 'name' => $foreignUser->name]);
    }

    public function test_update_swaps_the_targeted_users_role(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $editorRole = Rol::create(['type' => RoleType::Editor]);
        Rol::create(['type' => RoleType::Reader]);
        $peer = User::factory()->for($institution)->create();
        $peer->roles()->attach($editorRole->id);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
            'role' => 'reader',
        ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [], 'message' => 'Account updated successfully.']);

        $this->assertTrue($peer->fresh()->roles()->where('type', RoleType::Reader)->exists());
        $this->assertFalse($peer->fresh()->roles()->where('type', RoleType::Editor)->exists());
    }

    public function test_update_changes_the_targeted_users_active_status(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $peer = User::factory()->for($institution)->create(['active' => true]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
            'active' => false,
        ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [], 'message' => 'Account updated successfully.']);

        $this->assertDatabaseHas('users', ['id' => $peer->id, 'active' => false]);
    }

    public function test_destroy_soft_deletes_the_targeted_user(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $peer = User::factory()->for($institution)->create();

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
        ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [], 'message' => 'Account deleted successfully.']);

        $this->assertSoftDeleted('users', ['id' => $peer->id]);
    }

    public function test_destroy_removes_the_user_from_the_index(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $peer = User::factory()->for($institution)->create();

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $peer->email,
        ])->assertOk();

        $response = $this->getJson("/api/v1/institution/users?institution_id={$institution->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($peer->id, $ids);
    }

    public function test_destroy_cannot_distinguish_foreign_from_nonexistent_users(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $otherInstitution = Institution::factory()->create();
        $foreignUser = User::factory()->for($otherInstitution)->create();

        Sanctum::actingAs($admin);

        foreach ([$foreignUser->email, 'missing@example.com'] as $email) {
            $this->deleteJson('/api/v1/institution/users', [
                'institution_id' => $institution->id,
                'email' => $email,
            ])->assertNotFound()->assertExactJson([
                'success' => false,
                'message' => 'Institution user not found.',
            ]);
        }

        $this->assertDatabaseHas('users', ['id' => $foreignUser->id, 'deleted_at' => null]);
    }

    public function test_request_institution_id_cannot_expand_update_or_destroy_scope(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);
        $peer = User::factory()->for($institution)->create(['name' => 'Original']);
        $foreignInstitution = Institution::factory()->create();
        Sanctum::actingAs($admin);

        $payload = [
            'institution_id' => $foreignInstitution->id,
            'email' => $peer->email,
        ];

        $this->patchJson('/api/v1/institution/users', $payload + ['name' => 'Changed'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Institution user not found.']);
        $this->deleteJson('/api/v1/institution/users', $payload)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Institution user not found.']);

        $this->assertDatabaseHas('users', [
            'id' => $peer->id,
            'name' => 'Original',
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_rejects_self_deletion(): void
    {
        [$institution, $admin] = $this->institutionUser(admin: true);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/institution/users', [
            'institution_id' => $institution->id,
            'email' => $admin->email,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
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
