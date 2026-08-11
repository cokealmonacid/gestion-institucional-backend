<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class AuthenticationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_validates_required_fields_and_email_format(): void
    {
        $this->postJson('/api/v1/auth/login')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['email', 'password']]]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_invalid_login_cases_share_one_public_response_and_create_no_tokens(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'email' => 'known@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $unverifiedUser = User::factory()->unverified()->for($institution)->create([
            'email' => 'unverified@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $expectedResponse = [
            'success' => false,
            'error' => [
                'code' => 'AUTH_INVALID_CREDENTIALS',
                'message' => 'The provided credentials are invalid.',
            ],
        ];

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'correct-password',
        ])->assertUnauthorized()->assertExactJson($expectedResponse);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized()->assertExactJson($expectedResponse);

        $this->postJson('/api/v1/auth/login', [
            'email' => $unverifiedUser->email,
            'password' => 'correct-password',
        ])->assertUnauthorized()->assertExactJson($expectedResponse);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_accepts_an_existing_valid_password_shorter_than_eight_characters(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'email' => 'short-password@example.test',
            'password' => Hash::make('short'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'short',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_rejects_an_inactive_account(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->inactive()->for($institution)->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertForbidden()->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_ACCOUNT_INACTIVE',
                'message' => 'This account is inactive.',
            ],
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_requires_an_existing_institution_before_creating_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'without-institution@example.test',
            'password' => Hash::make('correct-password'),
            'institution_id' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertForbidden()->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'AUTH_INSTITUTION_REQUIRED',
                'message' => 'A valid institution is required to access the application.',
            ],
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_returns_the_contractual_user_sorted_roles_and_token(): void
    {
        $institution = Institution::factory()->create(['name' => 'Acervo Test']);
        $user = User::factory()->for($institution)->create([
            'name' => 'Contract User',
            'email' => 'contract@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $reader = Rol::create(['type' => RoleType::Reader]);
        $admin = Rol::create(['type' => RoleType::Admin]);
        $user->roles()->attach([$reader->id, $admin->id]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => 'Contract User',
                    'email' => 'contract@example.test',
                    'institution' => [
                        'id' => $institution->id,
                        'name' => 'Acervo Test',
                    ],
                    'roles' => ['admin', 'reader'],
                ],
            ],
            'message' => 'Login successful.',
        ])->assertJsonStructure(['data' => ['token']]);
    }

    public function test_profile_returns_the_same_user_contract_without_a_token(): void
    {
        $institution = Institution::factory()->create(['name' => 'Acervo Test']);
        $user = User::factory()->for($institution)->create();
        $role = Rol::create(['type' => RoleType::Editor]);
        $user->roles()->attach($role);
        $token = $user->createToken('profile-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'institution' => [
                            'id' => $institution->id,
                            'name' => 'Acervo Test',
                        ],
                        'roles' => ['editor'],
                    ],
                ],
                'message' => 'Profile retrieved successfully.',
            ]);

        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('token', $response->json('data.user'));
    }

    public function test_profile_and_logout_use_the_contractual_unauthenticated_response(): void
    {
        $expectedResponse = [
            'success' => false,
            'error' => [
                'code' => 'AUTH_UNAUTHENTICATED',
                'message' => 'Authentication is required.',
            ],
        ];

        $this->getJson('/api/v1/user/profile')
            ->assertUnauthorized()
            ->assertExactJson($expectedResponse);

        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertExactJson($expectedResponse);
    }

    public function test_logout_revokes_only_the_current_access_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-token');
        $otherToken = $user->createToken('other-token');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => null,
                'message' => 'Logout successful.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->assertNull(PersonalAccessToken::findToken($currentToken->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($otherToken->plainTextToken));
    }
}
