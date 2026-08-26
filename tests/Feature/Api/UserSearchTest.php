<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->for(Institution::factory())->create();
        $role = Rol::create(['type' => RoleType::Admin]);
        $admin->roles()->attach($role);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_a_non_admin_user_is_forbidden(): void
    {
        $user = User::factory()->for(Institution::factory())->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/search?q=jane')
            ->assertForbidden();
    }

    public function test_an_admin_can_search_users_by_name(): void
    {
        $this->actingAsAdmin();

        $match = User::factory()->for(Institution::factory())->create(['name' => 'Jane Doe']);
        User::factory()->for(Institution::factory())->create(['name' => 'John Smith']);

        $this->getJson('/api/v1/user/search?q=Jane')
            ->assertOk()
            ->assertJsonPath('data.users.0.id', $match->id)
            ->assertJsonCount(1, 'data.users');
    }

    public function test_an_admin_can_search_users_by_email(): void
    {
        $this->actingAsAdmin();

        $match = User::factory()->for(Institution::factory())->create(['email' => 'match@example.com']);
        User::factory()->for(Institution::factory())->create(['email' => 'other@example.com']);

        $this->getJson('/api/v1/user/search?q=match@example.com')
            ->assertOk()
            ->assertJsonPath('data.users.0.id', $match->id)
            ->assertJsonCount(1, 'data.users');
    }

    public function test_inactive_users_are_excluded_from_results(): void
    {
        $this->actingAsAdmin();

        User::factory()->inactive()->for(Institution::factory())->create(['name' => 'Jane Inactive']);

        $this->getJson('/api/v1/user/search?q=Jane')
            ->assertOk()
            ->assertJsonCount(0, 'data.users');
    }

    public function test_query_shorter_than_two_characters_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/user/search?q=j')
            ->assertStatus(422);
    }
}
