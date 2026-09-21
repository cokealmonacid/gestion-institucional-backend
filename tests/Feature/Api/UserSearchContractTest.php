<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class UserSearchContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operation_requires_authentication(): void
    {
        $this->getJson('/api/v1/user/search?q=jane')->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_editor_reader_and_roleless_users_are_forbidden(): void
    {
        foreach ([RoleType::Editor, RoleType::Reader, null] as $role) {
            Sanctum::actingAs($this->institutionUser($role));
            $this->getJson('/api/v1/user/search?q=jane')->assertForbidden()
                ->assertExactJson(['message' => 'Forbidden.']);
        }
    }

    public function test_a_user_without_an_institution_is_forbidden_even_with_admin_role(): void
    {
        $admin = User::factory()->create(['institution_id' => null]);
        $admin->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Admin]));
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/user/search?q=jane')->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_multiple_roles_follow_the_central_users_manage_union(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->institutionUser(RoleType::Admin, $institution);
        $admin->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Reader]));
        $match = User::factory()->for($institution)->create(['name' => 'Jane Local']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/user/search?q=Jane')->assertOk()
            ->assertJsonPath('data.users.0.id', $match->id);
    }

    public function test_an_admin_finds_an_active_local_user_by_name_with_only_public_fields(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs($this->institutionUser(RoleType::Admin, $institution));
        $match = User::factory()->for($institution)->create(['name' => 'Jane Doe']);
        User::factory()->for($institution)->create(['name' => 'John Smith']);

        $response = $this->getJson('/api/v1/user/search?q=jAnE')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Users retrieved successfully.')
            ->assertJsonCount(1, 'data.users');

        $entry = $response->json('data.users.0');
        $this->assertSame(['id', 'name', 'email'], array_keys($entry));
        $this->assertSame($match->id, $entry['id']);
    }

    public function test_an_admin_finds_an_active_local_user_by_email(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs($this->institutionUser(RoleType::Admin, $institution));
        $match = User::factory()->for($institution)->create(['email' => 'match@example.com']);
        User::factory()->for($institution)->create(['email' => 'other@example.com']);

        $this->getJson('/api/v1/user/search?q=MATCH@example.com')->assertOk()
            ->assertJsonPath('data.users.0.id', $match->id)
            ->assertJsonCount(1, 'data.users');
    }

    public function test_a_shared_term_returns_only_the_local_user_and_exposes_no_foreign_data(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs($this->institutionUser(RoleType::Admin, $institution));
        $local = User::factory()->for($institution)->create(['name' => 'Shared Local', 'email' => 'local-shared@example.com']);
        $foreign = User::factory()->for(Institution::factory())->create(['name' => 'Shared Foreign', 'email' => 'foreign-shared@example.com']);

        $response = $this->getJson('/api/v1/user/search?q=shared')->assertOk()
            ->assertJsonCount(1, 'data.users')->assertJsonPath('data.users.0.id', $local->id);
        $body = $response->getContent();
        $this->assertStringNotContainsString($foreign->id, $body);
        $this->assertStringNotContainsString($foreign->name, $body);
        $this->assertStringNotContainsString($foreign->email, $body);
    }

    public function test_a_foreign_only_match_returns_an_empty_result(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs($this->institutionUser(RoleType::Admin, $institution));
        $foreign = User::factory()->for(Institution::factory())->create(['name' => 'Confidential Needle', 'email' => 'confidential-needle@example.com']);

        $response = $this->getJson('/api/v1/user/search?q=needle')->assertOk()->assertJsonCount(0, 'data.users');
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString($foreign->name, $response->getContent());
        $this->assertStringNotContainsString($foreign->email, $response->getContent());
    }

    public function test_institution_and_active_predicates_apply_to_both_name_and_email_matches(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs($this->institutionUser(RoleType::Admin, $institution));
        $local = User::factory()->for($institution)->create(['name' => 'Scope Match']);
        $inactive = User::factory()->inactive()->for($institution)->create(['email' => 'scope-match@example.com']);
        $foreignByName = User::factory()->for(Institution::factory())->create(['name' => 'Scope Foreign']);
        $foreignByEmail = User::factory()->for(Institution::factory())->create(['email' => 'scope-foreign@example.com']);

        $response = $this->getJson('/api/v1/user/search?q=scope')->assertOk()
            ->assertJsonCount(1, 'data.users')->assertJsonPath('data.users.0.id', $local->id);

        foreach ([$inactive, $foreignByName, $foreignByEmail] as $excluded) {
            $this->assertStringNotContainsString($excluded->id, $response->getContent());
        }
    }

    public function test_results_are_limited_to_ten(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs($this->institutionUser(RoleType::Admin, $institution));
        User::factory()->count(12)->for($institution)->create(['name' => 'Limit Match']);

        $this->getJson('/api/v1/user/search?q=Limit')->assertOk()->assertJsonCount(10, 'data.users');
    }

    public function test_query_validation_is_preserved(): void
    {
        Sanctum::actingAs($this->institutionUser(RoleType::Admin));
        $this->getJson('/api/v1/user/search?q=j')->assertStatus(422)
            ->assertJsonPath('success', false)->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('data.error.q.0', 'The q field must be at least 2 characters.');
        $this->getJson('/api/v1/user/search')->assertStatus(422)
            ->assertJsonPath('data.error.q.0', 'The q field is required.');
    }

    private function institutionUser(?RoleType $role, ?Institution $institution = null): User
    {
        $user = User::factory()->for($institution ?? Institution::factory())->create();
        if ($role) {
            $user->roles()->attach(Rol::firstOrCreate(['type' => $role]));
        }

        return $user;
    }
}
