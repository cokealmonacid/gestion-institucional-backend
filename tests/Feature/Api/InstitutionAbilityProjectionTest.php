<?php

namespace Tests\Feature\Api;

use App\Authorization\InstitutionAbilityProjection;
use App\Enums\InstitutionAbility;
use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class InstitutionAbilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_matches_each_role_and_roleless_union_semantics(): void
    {
        $institution = Institution::factory()->create();
        $expected = [
            RoleType::Admin->value => array_column(InstitutionAbility::cases(), 'value'),
            RoleType::Editor->value => [
                InstitutionAbility::View->value,
                InstitutionAbility::ManageDocuments->value,
                InstitutionAbility::ManageVersions->value,
                InstitutionAbility::ManageTags->value,
                InstitutionAbility::TagDocuments->value,
                InstitutionAbility::ManageComments->value,
                InstitutionAbility::ViewTraceability->value,
            ],
            RoleType::Reader->value => [
                InstitutionAbility::View->value,
                InstitutionAbility::ViewTraceability->value,
            ],
        ];

        foreach (RoleType::cases() as $type) {
            $user = $this->user($institution, [$type]);
            $this->assertSame($expected[$type->value], $this->projection()->project($user));
        }

        $roleless = User::factory()->for($institution)->create();
        $this->assertSame($expected[RoleType::Reader->value], $this->projection()->project($roleless));

        $multiple = $this->user($institution, [RoleType::Reader, RoleType::Editor]);
        $abilities = $this->projection()->project($multiple);
        $this->assertSame($expected[RoleType::Editor->value], $abilities);
        $this->assertSame($abilities, array_values(array_unique($abilities)));
    }

    public function test_user_without_institution_has_no_projected_abilities(): void
    {
        $user = User::factory()->create(['institution_id' => null]);

        $this->assertSame([], $this->projection()->project($user));
    }

    public function test_projection_loads_roles_at_most_once_and_reuses_a_loaded_relation(): void
    {
        $user = $this->user(Institution::factory()->create(), [RoleType::Editor]);
        $queries = $this->captureRoleQueries(fn () => $this->projection()->project($user));
        $this->assertCount(1, $queries);

        $queries = $this->captureRoleQueries(fn () => $this->projection()->project($user));
        $this->assertSame([], $queries);
    }

    public function test_projection_does_not_leak_between_users_and_next_profile_reflects_role_change(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->user($institution, [RoleType::Admin]);
        $reader = $this->user($institution, [RoleType::Reader]);

        $this->assertContains(InstitutionAbility::ManageUsers->value, $this->projection()->project($admin));
        $this->assertNotContains(InstitutionAbility::ManageUsers->value, $this->projection()->project($reader));

        Sanctum::actingAs($reader);
        $this->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('data.user.abilities', [
                InstitutionAbility::View->value,
                InstitutionAbility::ViewTraceability->value,
            ]);

        $reader->roles()->sync([Rol::firstOrCreate(['type' => RoleType::Editor])->id]);

        $this->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('data.user.abilities', [
                InstitutionAbility::View->value,
                InstitutionAbility::ManageDocuments->value,
                InstitutionAbility::ManageVersions->value,
                InstitutionAbility::ManageTags->value,
                InstitutionAbility::TagDocuments->value,
                InstitutionAbility::ManageComments->value,
                InstitutionAbility::ViewTraceability->value,
            ]);
    }

    public function test_login_and_profile_return_the_same_projection_for_the_same_user(): void
    {
        $user = $this->user(Institution::factory()->create(), [RoleType::Editor]);
        $user->update(['password' => Hash::make('correct-password')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $profile = $this->withToken($login->json('data.token'))
            ->getJson('/api/v1/user/profile')
            ->assertOk();

        $this->assertSame($login->json('data.user.abilities'), $profile->json('data.user.abilities'));
    }

    public function test_client_supplied_ability_cannot_bypass_endpoint_gate(): void
    {
        $reader = $this->user(Institution::factory()->create(), [RoleType::Reader]);
        Sanctum::actingAs($reader);

        $this->withHeader('X-Abilities', InstitutionAbility::ManageUsers->value)
            ->getJson('/api/v1/user/search?q=reader')
            ->assertForbidden();
    }

    /** @param list<RoleType> $types */
    private function user(Institution $institution, array $types): User
    {
        $user = User::factory()->for($institution)->create();

        foreach ($types as $type) {
            $user->roles()->attach(Rol::firstOrCreate(['type' => $type]));
        }

        return $user;
    }

    private function projection(): InstitutionAbilityProjection
    {
        return app(InstitutionAbilityProjection::class);
    }

    /** @return list<string> */
    private function captureRoleQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'role_user')) {
                $queries[] = $query->sql;
            }
        });

        $callback();

        return $queries;
    }
}
