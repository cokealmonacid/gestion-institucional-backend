<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use PHPUnit\Framework\Attributes\DataProvider;

class EmailControlCharacterRegressionTest extends PlatformTestCase
{
    #[DataProvider('accessRequests')]
    public function test_access_flows_reject_control_characters_without_persistence_or_mail(string $uri, string $email): void
    {
        $this->fakePublication();
        $before = $this->accessState();

        $this->postJson($uri, [
            'email' => $email,
            ...(str_ends_with($uri, 'password-recovery') ? [] : ['password' => 'Valid-test-password-123']),
            ...(str_ends_with($uri, 'activation') || str_ends_with($uri, 'password-reset') ? [
                'token' => 'synthetic-invalid-token',
                'password_confirmation' => 'Valid-test-password-123',
            ] : []),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);

        $this->assertSame($before, $this->accessState());
        $this->assertNoPublication();
    }

    #[DataProvider('unsafeEmails')]
    public function test_institution_invitation_rejects_control_characters_before_creating_user(string $email): void
    {
        $institution = Institution::factory()->create(['status' => true]);
        $admin = User::factory()->for($institution)->create();
        $admin->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Admin])->id);
        Rol::firstOrCreate(['type' => RoleType::Reader]);
        $this->authenticateInstitutionUser($admin);
        $this->fakePublication();
        $before = $this->accessState();

        $this->postJson('/api/v1/institution/user-invitations', [
            'institution_id' => $institution->id,
            'name' => 'Synthetic invitation',
            'email' => $email,
            'role' => 'reader',
        ])->assertUnprocessable()->assertJsonStructure(['data' => ['error' => ['email']]]);

        $this->assertSame($before, $this->accessState());
        $this->assertNoPublication();
    }

    #[DataProvider('unsafeEmails')]
    public function test_operator_bootstrap_rejects_control_characters_without_persistence_or_mail(string $email): void
    {
        $this->fakePublication();
        $before = $this->accessState();

        $this->assertSame(1, Artisan::call('platform:operator:bootstrap', [
            '--operation-id' => (string) Str::uuid(),
            '--name' => 'Synthetic operator',
            '--email' => $email,
            '--reason' => 'Regression fixture',
            '--accountability' => 'isolated-tests',
            '--no-interaction' => true,
        ]));
        $this->assertStringNotContainsString($email, Artisan::output());
        $this->assertSame($before, $this->accessState());
        $this->assertNoPublication();
    }

    public static function unsafeEmails(): array
    {
        return [
            'folded whitespace' => ["fixture\r\n @example.test"],
            'quoted local part' => ["\"fixture\r\n value\"@example.test"],
            'carriage return' => ["fixture\r@example.test"],
            'line feed' => ["fixture\n@example.test"],
        ];
    }

    public static function accessRequests(): array
    {
        $cases = [];
        foreach (['/api/v1/auth/', '/api/v1/platform/auth/'] as $prefix) {
            foreach (['login', 'password-recovery', 'password-reset', 'activation'] as $operation) {
                foreach (self::unsafeEmails() as $name => [$email]) {
                    $cases[$prefix.$operation.' '.$name] = [$prefix.$operation, $email];
                }
            }
        }

        return $cases;
    }

    private function accessState(): array
    {
        $state = [];
        foreach (['users', 'user_invitations', 'password_reset_tokens', 'personal_access_tokens', 'platform_operators', 'platform_auth_challenges', 'platform_access_links', 'platform_recovery_codes', 'platform_cli_operations', 'administrative_events'] as $table) {
            $state[$table] = DB::table($table)->get()->toJson();
        }

        return $state;
    }

    private function fakePublication(): void
    {
        Mail::fake();
        Queue::fake();
        Bus::fake();
    }

    private function assertNoPublication(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }
}
