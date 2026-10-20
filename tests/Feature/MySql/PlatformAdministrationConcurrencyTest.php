<?php

namespace Tests\Feature\MySql;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Jobs\SendInstitutionRecoveryProof;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\PlatformOperator;
use App\Platform\Models\PlatformRecoveryCode;
use App\Platform\Services\CommercialAdministration;
use App\Platform\Services\InstitutionAccessRecovery;
use App\Platform\Services\OperationalAdministration;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAuthentication;
use App\Services\UserAccess\InvitationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\RoleChangeImpact;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformAdministrationConcurrencyTest extends TestCase
{
    private array $operators = [];

    private array $institutions = [];

    private array $plans = [];

    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || getenv('MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Dedicated opt-in MySQL database unavailable.');
        }
        require base_path('tests/bootstrap-mysql.php');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && DB::getDriverName() === 'mysql' && getenv('MYSQL_CONCURRENCY_TESTS') === '1') {
            foreach (DB::table('platform_operations')->whereIn('operator_id', $this->operators)->pluck('result') as $raw) {
                $result = json_decode($raw, true);
                if (isset($result['institution_id'])) {
                    $this->institutions[] = $result['institution_id'];
                }
            }
            $this->institutions = array_values(array_unique($this->institutions));
            DB::table('platform_operations')->whereIn('operator_id', $this->operators)->delete();
            DB::table('administrative_events')->whereIn('actor_id', $this->operators)->orWhereIn('institution_id', $this->institutions)->orWhere('actor_id', 'concurrency-fixture')->delete();
            foreach (['platform_auth_challenges', 'platform_access_links', 'platform_recovery_codes'] as $table) {
                DB::table($table)->whereIn('operator_id', $this->operators)->delete();
            }
            DB::table('platform_cli_operations')->whereIn('operator_id', $this->operators)->delete();
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $this->operators)->delete();
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $this->users)->delete();
            $tenantUsers = DB::table('users')->whereIn('institution_id', $this->institutions)->pluck('id');
            $tenantEmails = DB::table('users')->whereIn('institution_id', $this->institutions)->pluck('email');
            DB::table('password_reset_tokens')->whereIn('email', $tenantEmails)->delete();
            DB::table('user_invitations')->whereIn('user_id', $tenantUsers)->delete();
            DB::table('role_user')->whereIn('user_id', $tenantUsers)->delete();
            DB::table('documents')->whereIn('institution_id', $this->institutions)->delete();
            DB::table('platform_access_recoveries')->whereIn('institution_id', $this->institutions)->delete();
            DB::table('users')->whereIn('id', $tenantUsers)->delete();
            DB::table('institution_contract_revisions')->whereIn('institution_id', $this->institutions)->delete();
            DB::table('institution_contracts')->whereIn('institution_id', $this->institutions)->delete();
            DB::table('institutions')->whereIn('id', $this->institutions)->delete();
            DB::table('catalog_plans')->whereIn('id', $this->plans)->delete();
            DB::table('platform_operators')->whereIn('id', $this->operators)->delete();
        }
        parent::tearDown();
    }

    private function operator(): PlatformOperator
    {
        $operator = PlatformOperator::create(['name' => 'Concurrency fixture', 'email' => Str::uuid().'@example.test', 'password' => 'Concurrency-password-123', 'verified_at' => now(), 'mfa_confirmed_at' => now(), 'totp_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->operators[] = $operator->id;

        return $operator;
    }

    private function token(PlatformOperator $operator): string
    {
        $issued = $operator->createToken('fixture', ['platform'], now()->addHour());
        $issued->accessToken->forceFill(['platform_auth_epoch' => $operator->auth_epoch, 'platform_mfa_verified_at' => now()])->save();
        $operator->withAccessToken($issued->accessToken);

        return $issued->plainTextToken;
    }

    public function test_totp_is_accepted_only_once_across_connections(): void
    {
        $operator = $this->operator();
        $challenge = app(PlatformAuthentication::class)->login($operator->email, 'Concurrency-password-123');
        $payload = array_intersect_key($challenge, array_flip(['challenge_id', 'challenge_token'])) + ['code' => TOTP::createFromSecret($operator->totp_secret)->at(now()->timestamp)];
        $results = $this->race('platform_operators', $operator->id, [['proof', $payload], ['proof', $payload]]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(1, $operator->tokens()->count());
    }

    public function test_recovery_code_is_consumed_only_once_across_connections(): void
    {
        $operator = $this->operator();
        $plain = Str::random(32);
        PlatformRecoveryCode::create(['operator_id' => $operator->id, 'code_hash' => Hash::make($plain)]);
        $challenge = app(PlatformAuthentication::class)->login($operator->email, 'Concurrency-password-123');
        $payload = array_intersect_key($challenge, array_flip(['challenge_id', 'challenge_token'])) + ['recovery_code' => $plain];
        $results = $this->race('platform_operators', $operator->id, [['proof', $payload], ['proof', $payload]]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(0, $operator->tokens()->count());
        $this->assertNull($operator->fresh()->mfa_confirmed_at);
    }

    public function test_simultaneous_failed_proofs_do_not_lose_attempt_accounting(): void
    {
        $operator = $this->operator();
        $challenge = app(PlatformAuthentication::class)->login($operator->email, 'Concurrency-password-123');
        $payload = array_intersect_key($challenge, array_flip(['challenge_id', 'challenge_token'])) + ['code' => 'invalid'];
        $this->race('platform_operators', $operator->id, [['proof', $payload], ['proof', $payload]]);
        $this->assertDatabaseHas('platform_auth_challenges', ['id' => $challenge['challenge_id'], 'attempts' => 2]);
    }

    public function test_optimistic_plan_and_contract_revisions_do_not_overwrite(): void
    {
        $operator = $this->operator();
        $bearer = $this->token($operator);
        $service = app(CommercialAdministration::class);
        $plan = $service->plan($operator, ['code' => (string) Str::uuid(), 'name' => 'Fixture plan', 'description' => 'Fixture', 'available_for_new_contracts' => true, 'limits' => ['admin' => 1, 'editor' => 2, 'reader' => null]]);
        $this->plans[] = $plan->id;
        $data = ['name' => 'Edited', 'description' => 'Revision', 'available_for_new_contracts' => true, 'limits' => ['admin' => 1, 'editor' => 3, 'reader' => null], 'expected_revision' => 1, 'reason' => 'Concurrent edit'];
        $payload = ['actor' => $operator->id, 'bearer' => $bearer, 'plan' => $plan->id, 'data' => $data];
        $results = $this->race('platform_operators', $operator->id, [['plan', $payload], ['plan', $payload]]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(2, $plan->fresh()->revision);
        $i = Institution::factory()->create();
        $this->institutions[] = $i->id;
        $data = ['expected_revision' => 0, 'plan_id' => $plan->id, 'expected_plan_revision' => 2, 'agreement_kind' => 'free_trial', 'lifecycle' => 'agreed', 'limits' => $plan->fresh()->limits, 'price_clp' => '0', 'currency' => 'CLP', 'tax_treatment' => 'inclusive', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'ends_on' => null, 'trial' => ['type' => 'trial', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'], 'exceptions' => [], 'reason' => 'Concurrent agreement'];
        $payload = ['actor' => $operator->id, 'bearer' => $bearer, 'institution' => $i->id, 'data' => $data];
        $results = $this->race('platform_operators', $operator->id, [['contract', $payload], ['contract', $payload]]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(1, InstitutionContract::where('institution_id', $i->id)->first()->revision);
    }

    public function test_first_operator_bootstrap_is_idempotent_under_contention(): void
    {
        if (PlatformOperator::exists()) {
            $this->fail('The dedicated test database contains unrelated platform operators.');
        }
        $input = ['operation_id' => (string) Str::uuid(), 'name' => 'Bootstrap fixture', 'email' => Str::uuid().'@example.test', 'reason' => 'Concurrency bootstrap', 'accountability' => 'concurrency-fixture'];
        $results = $this->race('platform_bootstrap_lock', 1, [['bootstrap', $input], ['bootstrap', $input]]);
        $this->operators[] = PlatformOperator::where('email', $input['email'])->firstOrFail()->id;
        $this->assertSame(2, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(1, DB::table('platform_cli_operations')->where('id', $input['operation_id'])->count());
        $this->assertSame(1, PlatformOperator::count());
    }

    private function tenant(array $limits = ['admin' => null, 'editor' => null, 'reader' => 1]): array
    {
        $operator = $this->operator();
        $operatorBearer = $this->token($operator);
        $institution = Institution::factory()->create(['status' => true]);
        $this->institutions[] = $institution->id;
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate(['type' => $role]);
        }
        $admin = User::factory()->for($institution)->create();
        $admin->roles()->attach(Rol::where('type', 'admin')->first()->id);
        $this->users[] = $admin->id;
        $adminBearer = $admin->createToken('concurrency')->plainTextToken;
        $service = app(CommercialAdministration::class);
        $plan = $service->plan($operator, ['code' => (string) Str::uuid(), 'name' => 'Quota fixture', 'description' => 'Synthetic', 'available_for_new_contracts' => true, 'limits' => $limits]);
        $this->plans[] = $plan->id;
        $agreement = ['expected_revision' => 0, 'plan_id' => $plan->id, 'expected_plan_revision' => 1, 'agreement_kind' => 'free_trial', 'lifecycle' => 'agreed', 'limits' => $limits, 'price_clp' => '0', 'currency' => 'CLP', 'tax_treatment' => 'inclusive', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'ends_on' => null, 'trial' => ['type' => 'trial', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'], 'exceptions' => [], 'reason' => 'Concurrency quota fixture'];
        $service->revise($operator, $institution->id, $agreement);

        return [$institution, $admin, $adminBearer, $operator, $operatorBearer, $agreement];
    }

    public function test_last_seat_admissions_use_current_reads_even_after_consistent_snapshots(): void
    {
        [$institution, $admin, $bearer] = $this->tenant();
        $payload = ['actor' => $admin->id, 'bearer' => $bearer, 'data' => ['name' => 'Race fixture', 'email' => Str::uuid().'@example.test', 'role' => 'reader']];
        $second = $payload;
        $second['data']['email'] = Str::uuid().'@example.test';
        $results = $this->race('institutions', $institution->id, [['tenant_invite_snapshot', $payload], ['tenant_invite_snapshot', $second]]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('USER_QUOTA_EXCEEDED', array_column($results, 'code'));
        $this->assertSame(2, User::where('institution_id', $institution->id)->count());
    }

    public function test_simultaneous_admin_deactivation_retains_one_usable_admin(): void
    {
        [$institution, $first, $bearer] = $this->tenant();
        $second = User::factory()->for($institution)->create();
        $second->roles()->attach(Rol::where('type', 'admin')->first()->id);
        $this->users[] = $second->id;
        $operations = [['tenant_update', ['actor' => $first->id, 'bearer' => $bearer, 'email' => $first->email, 'data' => ['active' => false]]],
            ['tenant_update', ['actor' => $second->id, 'bearer' => $second->createToken('concurrency')->plainTextToken, 'email' => $second->email, 'data' => ['active' => false]]]];
        $results = $this->race('institutions', $institution->id, $operations);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('INSTITUTION_REQUIRES_USABLE_ADMIN', array_column($results, 'code'));
        $this->assertSame(1, User::where('institution_id', $institution->id)->where('active', true)->count());
    }

    public function test_quota_reduction_racing_admission_has_one_committed_order(): void
    {
        [$institution, $admin, $bearer, $operator, $operatorBearer, $agreement] = $this->tenant();
        $agreement['expected_revision'] = 1;
        $agreement['limits']['reader'] = 0;
        $agreement['exceptions'] = [['condition' => 'limits.reader', 'base_value' => 1, 'agreed_value' => 0, 'reason' => 'Reduce admission']];
        $results = $this->race('institutions', $institution->id, [
            ['contract', ['actor' => $operator->id, 'bearer' => $operatorBearer, 'institution' => $institution->id, 'data' => $agreement]],
            ['tenant_invite', ['actor' => $admin->id, 'bearer' => $bearer, 'data' => ['name' => 'Race fixture', 'email' => Str::uuid().'@example.test', 'role' => 'reader']]],
        ]);
        $this->assertTrue($results[0]['completed']);
        $this->assertSame(1 + (int) $results[1]['completed'], User::where('institution_id', $institution->id)->count());
        $report = app(OperationalAdministration::class)->quotas($operator, $institution->id);
        $this->assertSame(0, $report['roles']['reader']['limit']);
        $this->assertSame((int) $results[1]['completed'], $report['roles']['reader']['over_by']);
    }

    public function test_role_change_competes_with_invitation_for_destination_role_capacity(): void
    {
        [$institution, $admin, $bearer] = $this->tenant(['admin' => null, 'editor' => 1, 'reader' => null]);
        $reader = User::factory()->for($institution)->create();
        $reader->roles()->attach(Rol::where('type', 'reader')->first()->id);
        $this->users[] = $reader->id;
        $results = $this->race('institutions', $institution->id, [
            ['tenant_update', ['actor' => $admin->id, 'bearer' => $bearer, 'email' => $reader->email, 'data' => ['role' => 'editor']]],
            ['tenant_invite', ['actor' => $admin->id, 'bearer' => $bearer, 'data' => ['name' => 'Race fixture', 'email' => Str::uuid().'@example.test', 'role' => 'editor']]],
        ]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('USER_QUOTA_EXCEEDED', array_column($results, 'code'));
    }

    public function test_pending_admin_activation_races_removal_using_actual_usable_state(): void
    {
        [$institution, $admin, $bearer] = $this->tenant();
        $pending = User::factory()->for($institution)->create(['password' => null, 'email_verified_at' => null]);
        $pending->roles()->attach(Rol::where('type', 'admin')->first()->id);
        $this->users[] = $pending->id;
        [, $plain] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($pending));
        $results = $this->race('institutions', $institution->id, [
            ['tenant_activate', ['credentials' => ['email' => $pending->email, 'token' => $plain, 'password' => 'Concurrency-password-123']]],
            ['tenant_update', ['actor' => $admin->id, 'bearer' => $bearer, 'email' => $admin->email, 'data' => ['active' => false]]],
        ]);
        $this->assertTrue($results[0]['completed']);
        $this->assertNotNull($pending->fresh()->email_verified_at);
        $this->assertSame(! $results[1]['completed'], $admin->fresh()->active);
    }

    public function test_platform_admin_repair_competes_for_every_existing_role_seat(): void
    {
        [$institution, $admin, $bearer, $operator, $operatorBearer] = $this->tenant(['admin' => null, 'editor' => 1, 'reader' => null]);
        $inactive = User::factory()->for($institution)->create(['active' => false]);
        $inactive->roles()->attach(Rol::whereIn('type', ['admin', 'editor'])->pluck('id'));
        $this->users[] = $inactive->id;
        $results = $this->race('institutions', $institution->id, [
            ['reactivate_admin', ['actor' => $operator->id, 'bearer' => $operatorBearer, 'institution' => $institution->id, 'user' => $inactive->id, 'data' => ['expected_user_revision' => 0, 'reason' => 'Concurrent repair']]],
            ['tenant_invite', ['actor' => $admin->id, 'bearer' => $bearer, 'data' => ['name' => 'Race fixture', 'email' => Str::uuid().'@example.test', 'role' => 'editor']]],
        ]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('USER_QUOTA_EXCEEDED', array_column($results, 'code'));
        $this->assertSame($results[0]['completed'], $inactive->fresh()->active);
        $this->assertCount(2, $inactive->fresh()->roles);
    }

    public function test_unrelated_institution_admission_completes_while_first_tenant_is_locked(): void
    {
        [$first] = $this->tenant();
        [$second, $admin, $bearer] = $this->tenant();
        $results = $this->race('institutions', $first->id, [['tenant_invite', ['actor' => $admin->id, 'bearer' => $bearer,
            'data' => ['name' => 'Independent fixture', 'email' => Str::uuid().'@example.test', 'role' => 'reader']]]], false);
        $this->assertTrue($results[0]['completed']);
        $this->assertSame(2, User::where('institution_id', $second->id)->count());
    }

    public static function suspensionRaces(): array
    {
        return array_map(fn ($operation) => [$operation], ['tenant_login', 'tenant_invite', 'tenant_activate', 'tenant_reset', 'tenant_password', 'tenant_document']);
    }

    #[DataProvider('suspensionRaces')]
    public function test_suspension_serializes_authentication_links_and_mutations(string $operation): void
    {
        [$institution, $admin, $bearer, $operator, $operatorBearer] = $this->tenant();
        $payload = ['actor' => $admin->id, 'bearer' => $bearer];
        if ($operation === 'tenant_login') {
            $payload['credentials'] = ['email' => $admin->email, 'password' => 'password'];
        }
        if ($operation === 'tenant_invite') {
            $payload['data'] = ['name' => 'Race fixture', 'email' => Str::uuid().'@example.test', 'role' => 'reader'];
        }
        if ($operation === 'tenant_activate') {
            $pending = User::factory()->for($institution)->create(['password' => null, 'email_verified_at' => null]);
            $pending->roles()->attach(Rol::where('type', 'reader')->first()->id);
            $this->users[] = $pending->id;
            [, $plain] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($pending));
            $payload['credentials'] = ['email' => $pending->email, 'token' => $plain, 'password' => 'Concurrency-password-123', 'password_confirmation' => 'Concurrency-password-123'];
        }
        if ($operation === 'tenant_reset') {
            $plain = Password::broker()->createToken($admin);
            $payload['credentials'] = ['email' => $admin->email, 'token' => $plain, 'password' => 'Concurrency-password-123', 'password_confirmation' => 'Concurrency-password-123'];
        }
        if ($operation === 'tenant_password') {
            $payload['credentials'] = ['current_password' => 'password', 'password' => 'Concurrency-password-123', 'password_confirmation' => 'Concurrency-password-123'];
        }
        if ($operation === 'tenant_document') {
            $document = Document::create(['name' => 'Race document', 'institution_id' => $institution->id, 'author_id' => $admin->id, 'status' => true]);
            $payload['document'] = $document->id;
        }
        $results = $this->race('institutions', $institution->id, [
            ['suspend', ['actor' => $operator->id, 'bearer' => $operatorBearer, 'institution' => $institution->id, 'data' => ['expected_revision' => 0, 'reason' => 'Suspension race']]], [$operation, $payload],
        ]);
        $this->assertTrue($results[0]['completed']);
        $this->assertFalse($institution->fresh()->status);
        $tenantIds = User::where('institution_id', $institution->id)->pluck('id');
        $this->assertSame(0, DB::table('personal_access_tokens')->whereIn('tokenable_id', $tenantIds)->count());
        $this->assertSame(0, DB::table('user_invitations')->whereIn('user_id', $tenantIds)->whereNotNull('token_hash')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->whereIn('email', User::whereIn('id', $tenantIds)->pluck('email'))->count());
        if ($operation === 'tenant_activate') {
            $this->assertSame($results[1]['completed'], $pending->fresh()->email_verified_at !== null);
        }
        if ($operation === 'tenant_document') {
            $this->assertSame($results[1]['completed'] ? 'Changed under lock' : null, $document->fresh()->description);
        }
    }

    private function onboardingContext(): array
    {
        $operator = $this->operator();
        $bearer = $this->token($operator);
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate(['type' => $role]);
        }
        $plan = app(CommercialAdministration::class)->plan($operator, ['code' => (string) Str::uuid(), 'name' => 'Onboarding fixture', 'description' => 'Synthetic', 'available_for_new_contracts' => true, 'limits' => ['admin' => 1, 'editor' => null, 'reader' => null]]);
        $this->plans[] = $plan->id;
        $data = ['institution' => ['name' => 'Concurrent onboarding'], 'customer' => null, 'initial_admin' => ['name' => 'Initial administrator', 'email' => Str::uuid().'@example.test'],
            'contract' => ['plan_id' => $plan->id, 'expected_plan_revision' => 1, 'agreement_kind' => 'free_trial', 'lifecycle' => 'agreed', 'limits' => $plan->limits, 'price_clp' => '0', 'currency' => 'CLP', 'tax_treatment' => 'inclusive', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'ends_on' => null, 'trial' => ['type' => 'trial', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'], 'exceptions' => [], 'reason' => 'Concurrent onboarding']];

        return [$operator, $bearer, $plan, $data];
    }

    public static function onboardingKeys(): array
    {
        return [['equivalent'], ['conflicting']];
    }

    #[DataProvider('onboardingKeys')]
    public function test_concurrent_onboarding_keys_create_one_resource_set_and_one_publication(string $kind): void
    {
        [$operator, $bearer, , $data] = $this->onboardingContext();
        $payload = ['actor' => $operator->id, 'bearer' => $bearer, 'key' => (string) Str::uuid(), 'data' => $data];
        $second = $payload;
        if ($kind === 'conflicting') {
            $second['data']['institution']['name'] = 'Conflicting request';
        } else {
            $second['data']['initial_admin']['email'] = strtoupper($second['data']['initial_admin']['email']);
        }
        $results = $this->race('platform_operators', $operator->id, [['customer_creation', $payload], ['customer_creation', $second]]);
        $this->assertSame($kind === 'equivalent' ? 2 : 1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(1, array_sum(array_column($results, 'publications')));
        $operations = DB::table('platform_operations')->where('operator_id', $operator->id)->get();
        $this->assertCount(1, $operations);
        $institutionId = json_decode($operations->first()->result, true)['institution_id'];
        $this->assertSame(1, User::where('institution_id', $institutionId)->count());
        $this->assertSame(1, DB::table('institution_contract_revisions')->where('institution_id', $institutionId)->count());
        if ($kind === 'conflicting') {
            $this->assertContains('IDEMPOTENCY_CONFLICT', array_column($results, 'code'));
        }
    }

    public function test_concurrent_distinct_operators_cannot_onboard_the_same_normalized_email(): void
    {
        [$operator, $bearer, , $data] = $this->onboardingContext();
        $second = $this->operator();
        $results = $this->race('catalog_plans', $data['contract']['plan_id'], [
            ['customer_creation', ['actor' => $operator->id, 'bearer' => $bearer, 'key' => (string) Str::uuid(), 'data' => $data]],
            ['customer_creation', ['actor' => $second->id, 'bearer' => $this->token($second), 'key' => (string) Str::uuid(), 'data' => $data]],
        ]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('EMAIL_UNAVAILABLE', array_column($results, 'code'));
        $this->assertSame(1, DB::table('platform_operations')->whereIn('operator_id', [$operator->id, $second->id])->count());
    }

    public function test_catalog_withdrawal_races_onboarding_without_mixed_or_rewritten_conditions(): void
    {
        [$operator, $bearer, $plan, $data] = $this->onboardingContext();
        $editor = $this->operator();
        $results = $this->race('catalog_plans', $plan->id, [
            ['customer_creation', ['actor' => $operator->id, 'bearer' => $bearer, 'key' => (string) Str::uuid(), 'data' => $data]],
            ['plan', ['actor' => $editor->id, 'bearer' => $this->token($editor), 'plan' => $plan->id, 'data' => ['expected_revision' => 1, 'name' => 'Withdrawn', 'description' => 'Changed catalog', 'limits' => $plan->limits, 'available_for_new_contracts' => false, 'reason' => 'Concurrent withdrawal']]],
        ]);
        $this->assertTrue($results[1]['completed']);
        if ($results[0]['completed']) {
            $revision = DB::table('institution_contract_revisions')->where('institution_id', $results[0]['result']['institution_id'])->first();
            $snapshot = json_decode($revision->conditions, true)['source_plan'];
            $this->assertSame(1, $snapshot['revision']);
            $this->assertSame('Onboarding fixture', $snapshot['name']);
        } else {
            $this->assertSame('PLAN_NOT_AVAILABLE', $results[0]['code']);
        }
    }

    public static function administratorKeys(): array
    {
        return [['duplicate'], ['last_seat']];
    }

    #[DataProvider('administratorKeys')]
    public function test_new_administrator_idempotency_and_quota_share_the_institution_boundary(string $kind): void
    {
        [$institution, , , $operator, $bearer] = $this->tenant(['admin' => 2, 'editor' => null, 'reader' => null]);
        $payload = ['actor' => $operator->id, 'bearer' => $bearer, 'key' => (string) Str::uuid(), 'institution' => $institution->id,
            'data' => ['name' => 'New administrator', 'email' => Str::uuid().'@example.test', 'reason' => 'Concurrent invitation']];
        $second = $payload;
        if ($kind === 'last_seat') {
            $second['key'] = (string) Str::uuid();
            $second['data']['email'] = Str::uuid().'@example.test';
        }
        $results = $this->race('institutions', $institution->id, [['customer_creation', $payload], ['customer_creation', $second]]);
        $this->assertSame($kind === 'duplicate' ? 2 : 1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(1, array_sum(array_column($results, 'publications')));
        $this->assertSame(2, User::where('institution_id', $institution->id)->count());
        if ($kind === 'last_seat') {
            $this->assertContains('USER_QUOTA_EXCEEDED', array_column($results, 'code'));
        }
    }

    private function preparedRecovery(User $target, PlatformOperator $operator, ?string $email = null): array
    {
        Bus::fake();
        $operation = app(InstitutionAccessRecovery::class)->prepare($operator, (string) Str::uuid(), [
            'kind' => 'email_correction', 'institution_id' => $target->institution_id, 'target_id' => $target->id,
            'expected_target_revision' => (int) $target->access_revision, 'expected_institution_revision' => 0,
            'new_email' => $email ?? Str::uuid().'@example.test', 'same_person' => true, 'confirmation' => true, 'reason' => 'Synthetic concurrency recovery',
            'authority' => ['requester' => 'Fixture', 'relationship' => 'Authorized representative', 'channel' => 'Fixture callback', 'reference' => 'Synthetic reference']]);
        $job = Bus::dispatched(SendInstitutionRecoveryProof::class)->last();

        return [$operation, $job->proof];
    }

    public function test_recovery_concurrent_completion_consumes_one_transition_and_records_one_audit(): void
    {
        [$institution, $target, , $operator, $bearer] = $this->tenant();
        $operation = $this->preparedReplacement($target, $operator);
        $payload = ['actor' => $operator->id, 'bearer' => $bearer, 'id' => $operation['id'], 'revision' => 2];
        $results = $this->race('institutions', $institution->id, [['recovery_complete', $payload], ['recovery_complete', $payload]]);
        $this->assertSame(2, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertSame(1, DB::table('administrative_events')->where('operation_id', $operation['id'])->where('action', 'access_recovery.complete')->count());
        $this->assertSame('completed', DB::table('platform_access_recoveries')->where('id', $operation['id'])->value('state'));
    }

    public function test_recovery_concurrent_verification_consumes_proof_once(): void
    {
        [$institution, $target, , $operator] = $this->tenant();
        [$operation, $proof] = $this->preparedRecovery($target, $operator);
        $payload = ['id' => $operation['id'], 'proof' => $proof];
        $results = $this->race('institutions', $institution->id, [['recovery_verify', $payload], ['recovery_verify', $payload]]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('RECOVERY_PROOF_INVALID', array_column($results, 'code'));
    }

    public function test_recovery_quota_revision_racing_completion_has_a_serial_committed_order(): void
    {
        [$institution, $target, , $operator, $bearer, $agreement] = $this->tenant(['admin' => 1, 'editor' => null, 'reader' => 1]);
        $operation = $this->preparedReplacement($target, $operator);
        $agreement['expected_revision'] = 1;
        $agreement['limits']['admin'] = 0;
        $agreement['exceptions'] = [['condition' => 'limits.admin', 'base_value' => 1, 'agreed_value' => 0, 'reason' => 'Synthetic concurrent reduction']];
        $results = $this->race('institutions', $institution->id, [
            ['recovery_complete', ['actor' => $operator->id, 'bearer' => $bearer, 'id' => $operation['id'], 'revision' => 2]],
            ['contract', ['actor' => $operator->id, 'bearer' => $bearer, 'institution' => $institution->id, 'data' => $agreement]],
        ]);
        $this->assertTrue($results[1]['completed']);
        if (! $results[0]['completed']) {
            $this->assertSame('RECOVERY_STALE', $results[0]['code']);
        }
        $this->assertSame(2, (int) DB::table('institution_contracts')->where('institution_id', $institution->id)->value('revision'));
    }

    private function preparedReplacement(User $target, PlatformOperator $operator): array
    {
        Bus::fake();
        $service = app(InstitutionAccessRecovery::class);
        $operation = $service->prepare($operator, (string) Str::uuid(), ['kind' => 'replacement', 'institution_id' => $target->institution_id,
            'target_id' => $target->id, 'expected_target_revision' => (int) $target->access_revision, 'expected_institution_revision' => 0,
            'successor_name' => 'Synthetic successor', 'successor_email' => Str::uuid().'@example.test', 'confirmation' => true, 'reason' => 'Synthetic replacement',
            'authority' => ['requester' => 'Fixture', 'relationship' => 'Representative', 'channel' => 'Fixture callback', 'reference' => 'Synthetic']]);
        $job = Bus::dispatched(SendInstitutionRecoveryProof::class)->last();
        $service->verify($operation['id'], $job->proof, ['password' => 'Synthetic-successor-password', 'password_confirmation' => 'Synthetic-successor-password']);

        return $operation;
    }

    public function test_recovery_competing_replacements_of_one_outgoing_admin_cannot_both_commit(): void
    {
        [$institution, $target, , $operator, $bearer] = $this->tenant(['admin' => 1, 'editor' => null, 'reader' => null]);
        $first = $this->preparedReplacement($target, $operator);
        $second = $this->preparedReplacement($target, $operator);
        $operations = array_map(fn ($op) => ['recovery_complete', ['actor' => $operator->id, 'bearer' => $bearer, 'id' => $op['id'], 'revision' => 2]], [$first, $second]);
        $results = $this->race('institutions', $institution->id, $operations);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('RECOVERY_STALE', array_column($results, 'code'));
        $this->assertSame(2, User::where('institution_id', $institution->id)->count());
        $this->assertFalse($target->fresh()->active);
    }

    public function test_recovery_pending_address_reservation_is_unique_across_institutions(): void
    {
        [$institution, $target, , $operator, $bearer] = $this->tenant();
        [$other, $otherTarget, , $otherOperator, $otherBearer] = $this->tenant();
        $email = Str::uuid().'@example.test';
        $operations = [];
        foreach ([[$target, $operator, $bearer], [$otherTarget, $otherOperator, $otherBearer]] as [$user, $actor, $credential]) {
            $operations[] = ['recovery_prepare', ['actor' => $actor->id, 'bearer' => $credential, 'id' => (string) Str::uuid(), 'data' => [
                'kind' => 'email_correction', 'institution_id' => $user->institution_id, 'target_id' => $user->id,
                'expected_target_revision' => 0, 'expected_institution_revision' => 0, 'new_email' => $email, 'same_person' => true, 'confirmation' => true,
                'reason' => 'Synthetic reservation race', 'authority' => ['requester' => 'Fixture', 'relationship' => 'Representative', 'channel' => 'Fixture', 'reference' => 'Synthetic']]]];
        }
        $results = $this->race('institutions', $institution->id, $operations);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['completed'])));
        $this->assertContains('EMAIL_UNAVAILABLE', array_column($results, 'code'));
        $this->assertSame(1, DB::table('platform_access_recoveries')->where('reserved_email', $email)->count());
    }

    public function test_platform_role_promotions_compete_for_one_admin_seat(): void
    {
        [$institution, , , $operator, $bearer] = $this->tenant(['admin' => 2, 'editor' => null, 'reader' => null]);
        $operations = [];
        foreach (['editor', 'reader'] as $role) {
            $user = User::factory()->for($institution)->create();
            $user->roles()->attach(Rol::firstOrCreate(['type' => RoleType::from($role)])->id);
            $operations[] = ['platform_role', ['actor' => $operator->id, 'bearer' => $bearer, 'institution' => $institution->id, 'user' => $user->id,
                'data' => ['role' => 'admin', 'expected_user_revision' => 0, 'reason' => 'Synthetic promotion']]];
        }
        $results = $this->race('institutions', $institution->id, $operations);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['completed'])));
        $this->assertContains('USER_QUOTA_EXCEEDED', array_column($results, 'code'));
        $this->assertSame(2, User::where('institution_id', $institution->id)->whereHas('roles', fn ($q) => $q->where('type', 'admin'))->count());
        foreach (User::where('institution_id', $institution->id)->get() as $user) {
            $this->assertSame(1, $user->roles()->count());
        }
    }

    public function test_platform_demotion_racing_deactivation_retains_one_usable_admin(): void
    {
        [$institution, $first, , $operator, $bearer] = $this->tenant();
        $second = User::factory()->for($institution)->create();
        $second->roles()->attach(Rol::where('type', 'admin')->firstOrFail()->id);
        $common = ['actor' => $operator->id, 'bearer' => $bearer, 'institution' => $institution->id];
        $results = $this->race('institutions', $institution->id, [
            ['platform_role', $common + ['user' => $first->id, 'data' => ['role' => 'editor', 'expected_user_revision' => 0, 'reason' => 'Synthetic demotion']]],
            ['platform_access', $common + ['user' => $second->id, 'data' => ['active' => false, 'expected_user_revision' => 0, 'reason' => 'Synthetic deactivation']]],
        ]);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['completed'])));
        $this->assertContains('INSTITUTION_REQUIRES_USABLE_ADMIN', array_column($results, 'code'));
        $this->assertSame(1, User::where('institution_id', $institution->id)->where('active', true)->whereHas('roles', fn ($q) => $q->where('type', 'admin'))->count());
        $this->assertTrue($first->fresh()->active);
        $this->assertTrue($second->roles()->where('type', 'admin')->exists());
    }

    private function deactivationInput(PlatformOperator $operator): array
    {
        return ['operator_id' => $operator->id, 'operation_id' => (string) Str::uuid(), 'reason' => 'Synthetic operator departure', 'accountability' => 'concurrency-fixture', 'confirmation' => true];
    }

    public function test_operator_deactivation_replay_commits_one_revocation_and_audit(): void
    {
        $this->operator();
        $target = $this->operator();
        $this->token($target);
        $payload = $this->deactivationInput($target);
        $results = $this->race('platform_bootstrap_lock', 1, [['operator_deactivate', $payload], ['operator_deactivate', $payload]]);
        $this->assertSame(2, count(array_filter($results, fn ($result) => $result['completed'])));
        $this->assertFalse($target->fresh()->active);
        $this->assertSame(1, $target->fresh()->auth_epoch);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(1, DB::table('administrative_events')->where('operation_id', $payload['operation_id'])->where('action', 'operator.deactivate')->count());
    }

    public function test_competing_operator_deactivations_cannot_remove_the_last_usable_operator(): void
    {
        $first = $this->operator();
        $second = $this->operator();
        $results = $this->race('platform_bootstrap_lock', 1, [['operator_deactivate', $this->deactivationInput($first)], ['operator_deactivate', $this->deactivationInput($second)]]);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['completed'])));
        $this->assertContains('PLATFORM_REQUIRES_USABLE_OPERATOR', array_column($results, 'code'));
        $this->assertSame(1, PlatformOperator::where('active', true)->count());
    }

    public function test_operator_deactivation_racing_old_activation_never_restores_access(): void
    {
        $this->operator();
        $target = $this->operator();
        $target->update(['verified_at' => null, 'password' => null, 'mfa_confirmed_at' => null, 'totp_secret' => null]);
        [$link, $proof] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($target, 'activation'));
        $results = $this->race('platform_operators', $target->id, [
            ['operator_deactivate', $this->deactivationInput($target)],
            ['operator_link_consume', ['purpose' => 'activation', 'data' => ['email' => $target->email, 'token' => $proof, 'password' => 'Synthetic-activated-password-123']]],
        ]);
        $this->assertTrue($results[0]['completed']);
        $this->assertFalse($target->fresh()->active);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNull($link->fresh()->token_hash);
        if (! $results[1]['completed']) {
            $this->assertSame('PLATFORM_LINK_INVALID', $results[1]['code']);
            $this->assertNull($target->fresh()->verified_at);
        }
    }

    public function test_platform_reader_demotion_racing_responsibility_reassignment_requires_current_impact(): void
    {
        [$institution, $admin, $adminBearer, $operator, $bearer] = $this->tenant();
        $editor = User::factory()->for($institution)->create();
        $editor->roles()->attach(Rol::where('type', 'editor')->firstOrFail()->id);
        $document = Document::create(['name' => 'Concurrency responsibility fixture', 'status' => true, 'institution_id' => $institution->id, 'author_id' => $admin->id, 'responsible_user_id' => $editor->id]);
        $impact = app(RoleChangeImpact::class)->inspect($editor, RoleType::Reader);
        $results = $this->race('institutions', $institution->id, [
            ['platform_role', ['actor' => $operator->id, 'bearer' => $bearer, 'institution' => $institution->id, 'user' => $editor->id,
                'data' => ['role' => 'reader', 'expected_user_revision' => 0, 'reason' => 'Synthetic demotion', 'confirmation' => true, 'confirm_responsibility_removal' => true, 'role_impact_token' => $impact['impact_token']]]],
            ['tenant_responsibility', ['actor' => $admin->id, 'bearer' => $adminBearer, 'document' => $document->id, 'responsible' => $admin->id, 'revision' => 0]],
        ]);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['completed'])));
        $this->assertContains($results[0]['completed'] ? 'DOCUMENT_RESPONSIBILITY_CONFLICT' : 'ROLE_CHANGE_IMPACT_STALE', array_column($results, 'code'));
        $this->assertSame($results[0]['completed'] ? null : $admin->id, $document->fresh()->responsible_user_id);
        $this->assertSame([$results[0]['completed'] ? 'reader' : 'editor'], $editor->roles()->pluck('type')->map(fn ($role) => $role->value)->all());
        $this->assertSame(1, DB::table('document_responsible_histories')->where('document_id', $document->id)->count());
        $this->assertDatabaseHas('document_responsible_histories', ['document_id' => $document->id,
            'actor_platform_operator_id' => $results[0]['completed'] ? $operator->id : null,
            'actor_user_id' => $results[0]['completed'] ? null : $admin->id]);
    }

    private function race(string $table, string|int $id, array $operations, bool $releaseBeforeWait = true): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'acervo-platform-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        DB::beginTransaction();
        try {
            DB::table($table)->where('id', $id)->lockForUpdate()->first();
            foreach ($operations as $index => [$operation,$payload]) {
                $paths = array_combine(['payload', 'ready', 'started', 'output'], array_map(fn ($kind) => $directory.DIRECTORY_SEPARATOR.$kind.'-'.$index, ['payload', 'ready', 'started', 'output']));
                file_put_contents($paths['payload'], json_encode($payload, JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/mysql-platform-worker.php'), $operation, $paths['payload'], $paths['ready'], $directory.DIRECTORY_SEPARATOR.'go', $paths['started'], $paths['output']], base_path(), null, null, 30);
                $process->start();
                $processes[] = [$process, $paths];
            }
            $this->waitFor(array_column(array_column($processes, 1), 'ready'));
            foreach ($processes as [, $paths]) {
                unlink($paths['payload']);
            }
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go', 'go');
            $this->waitFor(array_column(array_column($processes, 1), 'started'));
            if (! $releaseBeforeWait) {
                foreach ($processes as [$process]) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), 'Unrelated tenant was blocked.');
                }
            }
            usleep(100000);
            DB::commit();
            $results = [];
            foreach ($processes as [$process,$paths]) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Concurrency worker failed.');
                $results[] = json_decode(file_get_contents($paths['output']), true, 512, JSON_THROW_ON_ERROR);
            }
            foreach ($results as $result) {
                $this->assertNotSame('UNEXPECTED_TEST_FAILURE', $result['code'] ?? null);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            } foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }

    private function waitFor(array $paths): void
    {
        $deadline = microtime(true) + 10;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            if (microtime(true) > $deadline) {
                $this->fail('Concurrency barrier timeout.');
            } usleep(10000);
        }
    }
}
