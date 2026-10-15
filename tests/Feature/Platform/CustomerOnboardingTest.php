<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Jobs\SendUserInvitation;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use App\Platform\Models\PlatformOperation;
use App\Platform\Models\PlatformOperator;
use App\Platform\Services\CommercialAdministration;
use App\Platform\Services\CustomerOnboarding;
use App\Platform\Services\InvitationPublication;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use PHPUnit\Framework\Attributes\DataProvider;

class CustomerOnboardingTest extends PlatformTestCase
{
    private PlatformOperator $actor;

    private string $bearer;

    private array $payload;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        Rol::firstOrCreate(['type' => RoleType::Admin]);
        $this->actor = $this->operator();
        $this->bearer = $this->token($this->actor);
        $this->withToken($this->bearer);
        $this->key = (string) Str::uuid();
        $plan = app(CommercialAdministration::class)->plan($this->actor, ['code' => 'onboarding-fixture', 'name' => 'Fixture', 'description' => 'Synthetic', 'available_for_new_contracts' => true, 'limits' => ['admin' => 2, 'editor' => 0, 'reader' => null]]);
        $this->payload = ['institution' => ['name' => 'Institution fixture'], 'customer' => ['type' => 'legal_entity', 'name' => 'Synthetic entity', 'rut' => '12345678-5'], 'initial_admin' => ['name' => 'Administrator fixture', 'email' => 'onboarding@example.test'],
            'contract' => ['plan_id' => $plan->id, 'expected_plan_revision' => 1, 'agreement_kind' => 'paid', 'lifecycle' => 'agreed', 'limits' => $plan->limits, 'price_clp' => '0', 'currency' => 'CLP', 'tax_treatment' => 'inclusive', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'ends_on' => null, 'trial' => null, 'exceptions' => [], 'reason' => 'Onboarding fixture']];
    }

    private function onboard(?array $payload = null, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? $this->key)->postJson('/api/v1/platform/institutions', $payload ?? $this->payload);
    }

    public function test_onboarding_commits_complete_resources_and_replay_normalizes_equivalent_payload(): void
    {
        $result = $this->onboard()->assertCreated()->assertHeader('Idempotency-Replayed', 'false')->assertJsonPath('data.publication_status', 'queued')->json('data');
        $this->assertDatabaseCount('institutions', 1);
        $this->assertDatabaseCount('institution_contract_revisions', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('role_user', 1);
        $this->assertDatabaseCount('user_invitations', 1);
        $this->assertDatabaseCount('platform_operations', 1);
        $user = User::findOrFail($result['initial_admin_id']);
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->active);
        $this->assertTrue($user->roles()->where('type', 'admin')->exists());
        $payload = array_reverse($this->payload, true);
        $payload['customer']['rut'] = '12.345.678-5';
        $payload['initial_admin']['email'] = 'ONBOARDING@example.test';
        $payload['contract']['expected_plan_revision'] = '1';
        $payload['contract']['limits']['admin'] = '2';
        $payload['contract']['price_clp'] = '000';
        $this->onboard($payload, strtoupper($this->key))->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data', $result);
        Bus::assertDispatchedTimes(SendUserInvitation::class, 1);
        Mail::assertNothingSent();
        $stored = PlatformOperation::first();
        $this->assertArrayNotHasKey('request', $stored->getAttributes());
        $this->assertStringNotContainsString('onboarding@example.test', json_encode($stored->result));
        $this->getJson('/api/v1/platform/operations/'.$this->key.'?operation_type=institution_onboarding')->assertOk()->assertJsonPath('data.result.institution_id', $result['institution_id']);
    }

    public function test_conflicting_payload_owner_scope_and_operation_type_scope(): void
    {
        $first = $this->onboard()->assertCreated()->json('data');
        $changed = $this->payload;
        $changed['institution']['name'] = 'Different institution';
        $this->onboard($changed)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $other = $this->operator();
        $this->withToken($this->token($other))->getJson('/api/v1/platform/operations/'.$this->key.'?operation_type=institution_onboarding')->assertNotFound();
        $changed['initial_admin']['email'] = 'other-owner@example.test';
        $this->onboard($changed)->assertCreated();
        $this->withToken($this->bearer)->withHeader('Idempotency-Key', $this->key)->postJson('/api/v1/platform/institutions/'.$first['institution_id'].'/administrator-invitations', ['name' => 'Second administrator', 'email' => 'second-admin@example.test', 'reason' => 'Explicit new administrator'])->assertCreated();
        $this->assertDatabaseCount('platform_operations', 3);
    }

    public static function persistenceFailures(): array
    {
        return array_map(fn ($table) => [$table], ['users', 'role_user', 'user_invitations', 'institution_contract_revisions', 'platform_operations']);
    }

    #[DataProvider('persistenceFailures')]
    public function test_required_persistence_failure_rolls_back_every_resource(string $table): void
    {
        DB::unprepared("CREATE TRIGGER fail_onboarding BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'Synthetic failure'); END");
        $this->onboard()->assertStatus(500);
        foreach (['institutions', 'users', 'role_user', 'user_invitations', 'institution_contracts', 'institution_contract_revisions', 'platform_operations'] as $name) {
            $this->assertDatabaseCount($name, 0);
        }
        $this->assertSame(0, DB::table('administrative_events')->where('action', 'institution.onboarded')->count());
        Bus::assertNothingDispatched();
    }

    public function test_audit_failure_plan_revision_quota_and_email_failures_have_no_partial_onboarding(): void
    {
        $wrong = $this->payload;
        $wrong['contract']['expected_plan_revision'] = 2;
        $this->onboard($wrong)->assertConflict()->assertJsonPath('error.code', 'PLAN_REVISION_CONFLICT');
        $zero = $this->payload;
        $zero['contract']['limits']['admin'] = 0;
        $zero['contract']['exceptions'] = [['condition' => 'limits.admin', 'base_value' => 2, 'agreed_value' => 0, 'reason' => 'No admin capacity']];
        $this->onboard($zero)->assertConflict()->assertJsonPath('error.code', 'USER_QUOTA_EXCEEDED');
        $this->assertDatabaseCount('institutions', 0);
        DB::unprepared("CREATE TRIGGER fail_onboarding_audit BEFORE INSERT ON administrative_events WHEN NEW.action = 'institution.onboarded' BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        $this->onboard()->assertStatus(500);
        $this->assertDatabaseCount('institutions', 0);
        $this->assertDatabaseCount('platform_operations', 0);
        Bus::assertNothingDispatched();
    }

    public function test_queue_failure_preserves_committed_result_and_explicit_resend_recovers(): void
    {
        $originalBus = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Synthetic queue failure'));
        $result = $this->onboard()->assertCreated()->assertJsonPath('data.publication_status', 'failed')->json('data');
        $this->assertDatabaseHas('users', ['id' => $result['initial_admin_id']]);
        $this->onboard()->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        Bus::swap($originalBus);
        $this->travel(61)->seconds();
        $this->postJson($result['resend_url'], ['reason' => 'Explicit recovery'])->assertStatus(202)->assertJsonPath('data.publication_status', 'queued');
        $this->assertSame(2, UserInvitation::find($result['invitation_id'])->generation);
        Bus::assertDispatchedTimes(SendUserInvitation::class, 1);
    }

    public function test_post_commit_status_write_failure_is_unknown_and_resources_are_discoverable(): void
    {
        DB::unprepared("CREATE TRIGGER fail_outcome BEFORE UPDATE ON platform_operations WHEN OLD.committed_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Synthetic outcome failure'); END");
        $result = $this->onboard()->assertCreated()->assertJsonPath('data.publication_status', 'unknown')->json('data');
        $this->assertDatabaseHas('users', ['id' => $result['initial_admin_id']]);
        $this->getJson('/api/v1/platform/operations/'.$this->key.'?operation_type=institution_onboarding')->assertOk()->assertJsonPath('data.result.institution_id', $result['institution_id']);
        $this->onboard()->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        Bus::assertDispatchedTimes(SendUserInvitation::class, 1);
    }

    public function test_nested_transaction_does_not_publish_before_its_outer_commit_and_rollbacks_publish_nothing(): void
    {
        DB::beginTransaction();
        $result = app(CustomerOnboarding::class)->create($this->actor, $this->key, $this->payload);
        Bus::assertNothingDispatched();
        $this->assertSame('unknown', $result['result']['publication_status']);
        DB::rollBack();
        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('institutions', 0);
        DB::beginTransaction();
        app(CustomerOnboarding::class)->create($this->actor, $this->key, $this->payload);
        DB::commit();
        Bus::assertDispatchedTimes(SendUserInvitation::class, 1);
    }

    public function test_refresh_and_failed_status_marker_errors_return_unknown_after_commit(): void
    {
        $this->partialMock(InvitationPublication::class)->shouldAllowMockingProtectedMethods()->shouldReceive('current')->andThrow(new \RuntimeException('Synthetic refresh failure'));
        $result = $this->onboard()->assertCreated()->assertJsonPath('data.publication_status', 'unknown')->json('data');
        $this->assertDatabaseHas('users', ['id' => $result['initial_admin_id']]);
        $this->onboard()->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        Bus::assertDispatchedTimes(SendUserInvitation::class, 1);
    }

    public function test_queue_failure_with_unwritable_failure_marker_still_returns_committed_identifiers(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Synthetic queue failure'));
        DB::unprepared("CREATE TRIGGER fail_marker BEFORE UPDATE ON user_invitations WHEN NEW.delivery_status = 'failed' BEGIN SELECT RAISE(ABORT, 'Synthetic marker failure'); END");
        $result = $this->onboard()->assertCreated()->assertJsonPath('data.publication_status', 'unknown')->json('data');
        $this->assertDatabaseHas('user_invitations', ['id' => $result['invitation_id'], 'delivery_status' => 'pending']);
        $this->getJson('/api/v1/platform/operations/'.$this->key.'?operation_type=institution_onboarding')->assertOk();
    }

    public function test_new_administrator_invitation_replays_and_enforces_quota_status_and_strict_role_ownership(): void
    {
        $initial = $this->onboard()->assertCreated()->json('data');
        $path = '/api/v1/platform/institutions/'.$initial['institution_id'].'/administrator-invitations';
        $invite = ['name' => 'Explicit administrator', 'email' => 'new-admin@example.test', 'reason' => 'Access repair'];
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)->postJson($path, $invite)->assertCreated()->json('data');
        $this->postJson($path, $invite)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data', $created);
        $this->postJson($path, array_replace($invite, ['name' => 'Different']))->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($path, array_replace($invite, ['email' => 'quota-admin@example.test']))->assertConflict()->assertJsonPath('error.code', 'USER_QUOTA_EXCEEDED');
        $this->postJson($path, $invite + ['role' => 'reader'])->assertUnprocessable();
        $this->assertNull(User::find($created['administrator_id'])->password);
        $this->postJson('/api/v1/platform/institutions/'.$initial['institution_id'].'/suspension', ['expected_revision' => 0, 'reason' => 'Suspended customer'])->assertOk();
        $this->postJson($path, array_replace($invite, ['email' => 'suspended-admin@example.test']))->assertForbidden();
        $this->assertDatabaseCount('users', 2);
        Bus::assertDispatchedTimes(SendUserInvitation::class, 2);
    }

    public function test_existing_emails_cannot_be_promoted_or_reused_including_deleted_and_legacy_case_variants(): void
    {
        $existing = User::factory()->for(Institution::factory())->create(['email' => 'LEGACY@example.test']);
        $existing->delete();
        $payload = $this->payload;
        $payload['initial_admin']['email'] = 'legacy@example.test';
        $this->onboard($payload)->assertConflict()->assertJsonPath('error.code', 'EMAIL_UNAVAILABLE');
        $this->assertDatabaseCount('platform_operations', 0);
        $this->assertDatabaseCount('institutions', 1);
        $this->assertSame(0, $existing->roles()->count());
        Bus::assertNothingDispatched();
    }

    public function test_stale_pending_lookup_and_detail_warnings_are_computed_not_persisted(): void
    {
        $result = $this->onboard()->assertCreated()->json('data');
        $this->travel(5)->minutes();
        $this->getJson('/api/v1/platform/operations/'.$this->key.'?operation_type=institution_onboarding')->assertOk()->assertJsonPath('data.result.publication_status', 'unknown');
        $this->assertSame('queued', PlatformOperation::first()->result['publication_status']);
        $this->getJson('/api/v1/platform/institutions/'.$result['institution_id'])->assertOk()
            ->assertJsonPath('data.current_contract.revision', 1)->assertJsonPath('data.quotas.roles.admin.used', 1)->assertJsonPath('data.warnings', ['NO_USABLE_ADMIN']);
        $this->getJson('/api/v1/platform/institutions/'.$result['institution_id'].'/audit?action=institution.onboarded')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_invalid_inputs_missing_keys_and_non_platform_principals_create_no_resources(): void
    {
        $this->postJson('/api/v1/platform/institutions', $this->payload)->assertUnprocessable();
        $wrong = $this->payload;
        $wrong['initial_admin']['email'] = "fixture\r\n @example.test";
        $this->onboard($wrong)->assertUnprocessable();
        $wrong = $this->payload;
        $wrong['initial_admin']['password'] = 'Must not be accepted';
        $this->onboard($wrong)->assertUnprocessable();
        $user = User::factory()->for(Institution::factory())->create();
        $this->withToken($user->createToken('fixture')->plainTextToken);
        $this->onboard()->assertUnauthorized();
        $this->assertDatabaseCount('platform_operations', 0);
        Bus::assertNothingDispatched();
    }

    public function test_free_trial_onboarding_accepts_optional_identity_and_requires_complete_trial_dates(): void
    {
        $payload = $this->payload;
        $payload['customer'] = null;
        $payload['contract']['agreement_kind'] = 'free_trial';
        $payload['contract']['trial'] = ['type' => 'trial', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'];
        $this->onboard($payload)->assertCreated();
        $bad = $this->payload;
        $bad['contract']['trial'] = [];
        $this->onboard($bad, (string) Str::uuid())->assertUnprocessable();
    }

    public function test_missing_administrator_role_rolls_back_onboarding_without_publication(): void
    {
        Rol::where('type', 'admin')->delete();
        $this->onboard()->assertStatus(500)->assertJsonPath('error.code', 'ADMINISTRATOR_ROLE_NOT_CONFIGURED');
        foreach (['institutions', 'institution_contracts', 'institution_contract_revisions', 'users', 'role_user', 'user_invitations', 'platform_operations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Bus::assertNothingDispatched();
    }

    public function test_new_administrator_audit_failure_rolls_back_only_the_attempted_admission(): void
    {
        $result = $this->onboard()->assertCreated()->json('data');
        DB::unprepared("CREATE TRIGGER fail_administrator_audit BEFORE INSERT ON administrative_events WHEN NEW.action = 'administrator.invited' BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/platform/institutions/'.$result['institution_id'].'/administrator-invitations', ['name' => 'Rejected administrator', 'email' => 'audit-admin@example.test', 'reason' => 'Audit rollback'])->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('role_user', 1);
        $this->assertDatabaseCount('user_invitations', 1);
        $this->assertDatabaseCount('platform_operations', 1);
        Bus::assertDispatchedTimes(SendUserInvitation::class, 1);
    }
}
