<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class OnboardingInstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_creates_an_institution_and_receives_a_full_session(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $pendingToken = $user->createToken('onboarding', ['onboarding'], now()->addDay())->plainTextToken;
        Rol::factory()->create(['type' => RoleType::Admin]);

        $response = $this->withToken($pendingToken)->postJson('/api/v1/onboarding/institution', [
            'name' => ' Biblioteca Central ',
        ])->assertCreated()
            ->assertJsonPath('data.user.institution.name', 'Biblioteca Central')
            ->assertJsonPath('data.user.roles', ['admin'])
            ->assertJsonPath('data.onboarding.state', 'ready');

        $institution = Institution::sole();
        $this->assertSame('Biblioteca Central', $institution->name);
        $this->assertSame($user->id, $institution->owner_user_id);
        $this->assertTrue($institution->status);
        $this->assertSame('ready', $institution->provisioning_status);
        $this->assertTrue($institution->onboarding_completed_at->isSameSecond(now()));
        $this->assertSame($institution->id, $user->fresh()->institution_id);
        $this->assertTrue($user->fresh()->roles()->where('type', RoleType::Admin)->exists());
        $this->assertNull(PersonalAccessToken::findToken($pendingToken));
        $this->assertSame(['*'], PersonalAccessToken::findToken($response->json('data.token'))->abilities);
    }

    public function test_retry_returns_the_same_owned_institution_without_renaming_it(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['name' => 'Original']);
        $institution->forceFill(['owner_user_id' => $user->id])->save();
        $user->update(['institution_id' => $institution->id]);

        $this->withToken($user->createToken('full', ['*'])->plainTextToken)
            ->postJson('/api/v1/onboarding/institution', ['name' => 'Different'])
            ->assertOk()
            ->assertJsonPath('data.user.institution.id', $institution->id);

        $this->assertSame('Original', $institution->fresh()->name);
        $this->assertDatabaseCount('institutions', 1);
    }

    public function test_user_already_assigned_to_another_institution_cannot_create_one(): void
    {
        $user = User::factory()->for(Institution::factory())->create();

        $this->withToken($user->createToken('full', ['*'])->plainTextToken)
            ->postJson('/api/v1/onboarding/institution', ['name' => 'New institution'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INSTITUTION_ALREADY_ASSIGNED');
        $this->assertDatabaseCount('institutions', 1);
    }

    public function test_unverified_user_cannot_create_an_institution(): void
    {
        $user = User::factory()->unverified()->create();

        $this->withToken($user->createToken('onboarding', ['onboarding'])->plainTextToken)
            ->postJson('/api/v1/onboarding/institution', ['name' => 'Institution'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    }

    public function test_request_rejects_empty_or_unexpected_fields(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('onboarding', ['onboarding'])->plainTextToken)
            ->postJson('/api/v1/onboarding/institution', [
                'name' => ' ', 'owner_id' => 'forged', 'role' => 'admin', 'quota' => 1,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'owner_id', 'role', 'quota']);
        $this->assertDatabaseCount('institutions', 0);
    }

    public function test_setup_failure_rolls_back_the_institution_and_user_assignment(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('onboarding', ['onboarding'])->plainTextToken)
            ->postJson('/api/v1/onboarding/institution', ['name' => 'Institution'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'INSTITUTION_SETUP_UNAVAILABLE');

        $this->assertDatabaseCount('institutions', 0);
        $this->assertNull($user->fresh()->institution_id);
    }

    public function test_owner_cannot_be_deactivated_demoted_or_removed_without_transfer(): void
    {
        $institution = Institution::factory()->create();
        $owner = User::factory()->for($institution)->create();
        $actor = User::factory()->for($institution)->create();
        $institution->forceFill(['owner_user_id' => $owner->id])->save();
        $admin = Rol::factory()->create(['type' => RoleType::Admin]);
        $owner->roles()->attach($admin);
        $actor->roles()->attach($admin);
        $token = $actor->createToken('full', ['*'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/v1/institution/users', [
            'email' => $owner->email,
            'institution_id' => $institution->id,
            'active' => false,
        ])->assertConflict()->assertJsonPath('code', 'INSTITUTION_OWNER_TRANSFER_REQUIRED');

        $this->withToken($token)->patchJson('/api/v1/institution/users', [
            'email' => $owner->email,
            'institution_id' => $institution->id,
            'role' => RoleType::Reader->value,
        ])->assertConflict()->assertJsonPath('code', 'INSTITUTION_OWNER_TRANSFER_REQUIRED');

        $this->withToken($token)->deleteJson('/api/v1/institution/users', [
            'email' => $owner->email,
            'institution_id' => $institution->id,
        ])->assertConflict()->assertJsonPath('code', 'INSTITUTION_OWNER_TRANSFER_REQUIRED');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'active' => true]);
        $this->assertNull($owner->fresh()->deleted_at);
    }

    public function test_owner_cannot_delete_their_blade_profile_without_transfer(): void
    {
        $institution = Institution::factory()->create();
        $owner = User::factory()->for($institution)->create(['password' => 'password']);
        $institution->forceFill(['owner_user_id' => $owner->id])->save();

        $this->actingAs($owner)->delete('/profile', ['password' => 'password'])
            ->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $owner->id]);
    }
}
