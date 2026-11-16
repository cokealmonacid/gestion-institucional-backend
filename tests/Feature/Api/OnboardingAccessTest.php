<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class OnboardingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_token_remains_blocked_after_database_changes_until_exchanged(): void
    {
        $institution = Institution::factory()->create(['provisioning_status' => 'ready']);
        $user = User::factory()->for($institution)->create();
        $token = $user->createToken('pending', ['onboarding'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/institutions')
            ->assertForbidden()->assertJsonPath('error.code', 'ONBOARDING_REQUIRED');
    }

    public function test_suspended_institution_blocks_existing_full_tokens_but_not_logout(): void
    {
        $institution = Institution::factory()->create(['status' => false]);
        $user = User::factory()->for($institution)->create();
        $token = $user->createToken('full', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/user/profile')
            ->assertOk()->assertJsonPath('data.onboarding.state', 'suspended')
            ->assertJsonPath('data.user.abilities', []);
        $this->withToken($token)->getJson('/api/v1/institutions')
            ->assertForbidden()->assertJsonPath('error.code', 'INSTITUTION_SUSPENDED');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($token));
    }
}
