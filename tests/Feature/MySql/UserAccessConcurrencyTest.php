<?php

namespace Tests\Feature\MySql;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserAccess\InvitationService;
use App\Services\UserAccess\PasswordRecoveryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Modules\Institution\Models\Institution;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UserAccessConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    /** @var list<string> */
    private array $institutionIds = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the opt-in dedicated MySQL concurrency suite.');
        }

        if (! self::$migrated) {
            Artisan::call('migrate', ['--force' => true]);
            self::$migrated = true;
        }

        config([
            'cache.default' => 'array',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'user-access.invitation_resend_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $this->userIds)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $this->emails)->delete();
            DB::table('user_invitations')->whereIn('user_id', $this->userIds)->delete();
            DB::table('role_user')->whereIn('user_id', $this->userIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
            DB::table('institutions')->whereIn('id', $this->institutionIds)->delete();
        }

        parent::tearDown();
    }

    public function test_two_simultaneous_invitation_consumptions_allow_only_one_to_complete(): void
    {
        [$institution, $user, $token] = $this->pendingInvitation();

        $results = $this->race($institution, [
            ['activate', ['credentials' => $this->activationCredentials($user, $token, 'first-password')]],
            ['activate', ['credentials' => $this->activationCredentials($user, $token, 'second-password')]],
        ]);

        $this->assertSame(1, collect($results)->where('completed', true)->count());
        $user->refresh();
        $invitation = $user->invitation()->firstOrFail();
        $this->assertTrue(Hash::check('first-password', $user->password) || Hash::check('second-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($invitation->token_hash);
        $this->assertNotNull($invitation->consumed_at);
    }

    public function test_invitation_reissue_racing_consumption_has_one_authoritative_generation(): void
    {
        [$institution, $user, $token, $admin] = $this->pendingInvitation(withAdmin: true);

        $results = $this->race($institution, [
            ['activate', ['credentials' => $this->activationCredentials($user, $token, 'activated-password')]],
            ['reissue_invitation', ['actor_id' => $admin->id, 'user_id' => $user->id]],
        ]);

        $this->assertSame(1, collect($results)->where('completed', true)->count());
        $user->refresh();
        $invitation = $user->invitation()->firstOrFail();
        if ($user->email_verified_at !== null) {
            $this->assertTrue(Hash::check('activated-password', $user->password));
            $this->assertNull($invitation->token_hash);
            $this->assertNotNull($invitation->consumed_at);
        } else {
            $this->assertNull($user->password);
            $this->assertSame(2, $invitation->generation);
            $this->assertNotNull($invitation->token_hash);
            $this->assertNull($invitation->consumed_at);
            $this->assertFalse(app(InvitationService::class)->activate($this->activationCredentials($user, $token, 'invalidated-token')));
        }
    }

    public function test_two_simultaneous_resets_with_the_same_token_allow_only_one_to_complete(): void
    {
        [$institution, $user, $token] = $this->recoverableUser();
        $user->createToken('session-one');
        $user->createToken('session-two');

        $results = $this->race($institution, [
            ['reset_password', ['credentials' => $this->resetCredentials($user, $token, 'first-recovery')]],
            ['reset_password', ['credentials' => $this->resetCredentials($user, $token, 'second-recovery')]],
        ]);

        $this->assertSame(1, collect($results)->where('completed', true)->count());
        $user->refresh();
        $this->assertTrue(Hash::check('first-recovery', $user->password) || Hash::check('second-recovery', $user->password));
        $this->assertFalse(Password::broker()->tokenExists($user, $token));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_recovery_reissue_racing_consumption_invalidates_the_original_token(): void
    {
        [$institution, $user, $token] = $this->recoverableUser();
        $user->createToken('existing-session');

        $results = $this->race($institution, [
            ['reset_password', ['credentials' => $this->resetCredentials($user, $token, 'race-recovery')]],
            ['issue_recovery', ['email' => $user->email]],
        ]);

        $this->assertTrue($results[1]['completed']);
        $user->refresh();
        $this->assertFalse(Password::broker()->tokenExists($user, $token));
        if ($results[0]['completed']) {
            $this->assertTrue(Hash::check('race-recovery', $user->password));
            $this->assertSame(0, $user->tokens()->count());
        } else {
            $this->assertTrue(Hash::check('old-password', $user->password));
            $this->assertSame(1, $user->tokens()->count());
        }
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_activation_racing_administrative_deactivation_cannot_activate_an_inactive_account_afterward(): void
    {
        [$institution, $user, $token, $admin] = $this->pendingInvitation(withAdmin: true);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        $results = $this->race($institution, [
            ['activate', ['credentials' => $this->activationCredentials($user, $token, 'activation-race')]],
            ['deactivate_user', [
                '_token' => $adminToken, 'email' => $user->email,
                'institution_id' => $institution->id, 'active' => false,
            ]],
        ]);

        $this->assertTrue($results[1]['completed']);
        $user->refresh();
        $this->assertFalse($user->active);
        $this->assertFalse(app(InvitationService::class)->activate($this->activationCredentials($user, $token, 'cannot-retry')));
        if ($results[0]['completed']) {
            $this->assertTrue(Hash::check('activation-race', $user->password));
            $this->assertNotNull($user->invitation->consumed_at);
        } else {
            $this->assertNull($user->password);
            $this->assertNotNull($user->invitation->token_hash);
        }
    }

    public function test_recovery_racing_administrative_deactivation_cannot_reset_an_inactive_account_afterward(): void
    {
        [$institution, $user, $token, $admin] = $this->recoverableUser(withAdmin: true);
        $adminToken = $admin->createToken('admin')->plainTextToken;
        $user->createToken('user-session');

        $results = $this->race($institution, [
            ['reset_password', ['credentials' => $this->resetCredentials($user, $token, 'deactivation-race')]],
            ['deactivate_user', [
                '_token' => $adminToken, 'email' => $user->email,
                'institution_id' => $institution->id, 'active' => false,
            ]],
        ]);

        $this->assertTrue($results[1]['completed']);
        $user->refresh();
        $this->assertFalse($user->active);
        $this->assertFalse(app(PasswordRecoveryService::class)->reset(
            $this->resetCredentials($user, $token, 'cannot-retry'),
        ));
        if ($results[0]['completed']) {
            $this->assertTrue(Hash::check('deactivation-race', $user->password));
            $this->assertSame(0, $user->tokens()->count());
            $this->assertFalse(Password::broker()->tokenExists($user, $token));
        } else {
            $this->assertTrue(Hash::check('old-password', $user->password));
            $this->assertSame(1, $user->tokens()->count());
        }
    }

    public function test_authenticated_change_racing_pending_recovery_leaves_one_password_and_no_tokens(): void
    {
        [$institution, $user, $token] = $this->recoverableUser();
        $session = $user->createToken('authenticated-change')->plainTextToken;

        $results = $this->race($institution, [
            ['change_password', [
                '_token' => $session, 'current_password' => 'old-password',
                'password' => 'authenticated-password', 'password_confirmation' => 'authenticated-password',
            ]],
            ['reset_password', ['credentials' => $this->resetCredentials($user, $token, 'recovered-password')]],
        ]);

        $this->assertSame(1, collect($results)->where('completed', true)->count());
        $user->refresh();
        $this->assertTrue(
            Hash::check('authenticated-password', $user->password)
            || Hash::check('recovered-password', $user->password),
        );
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertSame(0, $user->tokens()->count());
    }

    /** @return array{Institution, User, string, 3?: User} */
    private function pendingInvitation(bool $withAdmin = false): array
    {
        [$institution, $admin] = $this->institutionAndAdmin();
        $user = $this->trackUser(User::factory()->unverified()->for($institution)->create(['password' => null]));
        [$invitation, $token] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($user));
        $this->assertInstanceOf(UserInvitation::class, $invitation);

        return $withAdmin ? [$institution, $user, $token, $admin] : [$institution, $user, $token];
    }

    /** @return array{Institution, User, string, 3?: User} */
    private function recoverableUser(bool $withAdmin = false): array
    {
        [$institution, $admin] = $this->institutionAndAdmin();
        $user = $this->trackUser(User::factory()->for($institution)->create(['password' => 'old-password']));
        $token = Password::broker()->createToken($user);

        return $withAdmin ? [$institution, $user, $token, $admin] : [$institution, $user, $token];
    }

    /** @return array{Institution, User} */
    private function institutionAndAdmin(): array
    {
        $institution = Institution::factory()->create(['status' => true]);
        $this->institutionIds[] = $institution->id;
        $admin = $this->trackUser(User::factory()->for($institution)->create());
        $admin->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Admin]));

        return [$institution, $admin];
    }

    private function trackUser(User $user): User
    {
        $this->userIds[] = $user->id;
        $this->emails[] = $user->email;

        return $user;
    }

    /** @return array<string, string> */
    private function activationCredentials(User $user, string $token, string $password): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
    }

    /** @return array<string, string> */
    private function resetCredentials(User $user, string $token, string $password): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $operations
     * @return list<array<string, mixed>>
     */
    private function race(Institution $institution, array $operations): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'acervo-access-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $processes = [];

        DB::beginTransaction();
        try {
            Institution::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();

            foreach ($operations as $index => [$operation, $payload]) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
                $paths = [
                    'payload' => $payloadPath,
                    'ready' => $directory.DIRECTORY_SEPARATOR."ready-{$index}",
                    'started' => $directory.DIRECTORY_SEPARATOR."started-{$index}",
                    'output' => $directory.DIRECTORY_SEPARATOR."output-{$index}.json",
                ];
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/mysql-user-access-worker.php'), $operation,
                    $payloadPath, $paths['ready'], $goPath, $paths['started'], $paths['output'],
                ], base_path(), null, null, 30);
                $process->start();
                $processes[] = [$process, $paths];
            }

            $this->waitForFiles(array_column(array_column($processes, 1), 'ready'));
            foreach ($processes as [, $paths]) {
                unlink($paths['payload']);
            }
            file_put_contents($goPath, 'go', LOCK_EX);
            $this->waitForFiles(array_column(array_column($processes, 1), 'started'));
            usleep(100_000);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $results = [];
        foreach ($processes as [$process, $paths]) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = json_decode((string) file_get_contents($paths['output']), true, flags: JSON_THROW_ON_ERROR);
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);

        return $results;
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 10;
        while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for the concurrency barrier.');
            }
            usleep(10_000);
        }
    }
}
