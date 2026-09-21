<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class ActiveUserMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inactive_user_with_a_valid_token_is_rejected(): void
    {
        $user = User::factory()->inactive()->for(Institution::factory())->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/profile')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account is inactive.');
    }

    public function test_an_active_user_with_a_valid_token_is_allowed(): void
    {
        $user = User::factory()->for(Institution::factory())->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/profile')->assertOk();
    }
}
