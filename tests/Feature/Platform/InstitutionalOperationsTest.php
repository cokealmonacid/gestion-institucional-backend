<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Jobs\SendPasswordRecovery;
use App\Jobs\SendUserInvitation;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Models\PlatformOperator;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\Services\CommercialAdministration;
use App\Services\UserAccess\InvitationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Documents\Models\Document;
use Modules\Institution\Actions\InstitutionUserMutations;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Models\Institution;

class InstitutionalOperationsTest extends PlatformTestCase
{
    private PlatformOperator $operatorActor;

    private string $operatorToken;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate(['type' => $role]);
        }
        Bus::fake();
        Mail::fake();
        $this->operatorActor = $this->operator();
        $this->operatorToken = $this->token($this->operatorActor);
    }

    private function member(Institution $institution, array $roles = ['admin'], array $attributes = []): User
    {
        $user = User::factory()->for($institution)->create($attributes);
        foreach ($roles as $role) {
            $user->roles()->attach(Rol::firstOrCreate(['type' => RoleType::from($role)])->id);
        }

        return $user;
    }

    private function configure(Institution $institution, array $limits, int $expected = 0): void
    {
        $service = app(CommercialAdministration::class);
        $plan = $service->plan($this->operatorActor, ['code' => (string) Str::uuid(), 'name' => 'Boundary fixture', 'description' => 'Synthetic', 'available_for_new_contracts' => true, 'limits' => $limits]);
        $service->revise($this->operatorActor, $institution->id, ['expected_revision' => $expected, 'plan_id' => $plan->id, 'expected_plan_revision' => 1,
            'agreement_kind' => 'free_trial', 'lifecycle' => 'agreed', 'limits' => $limits, 'price_clp' => '0', 'currency' => 'CLP', 'tax_treatment' => 'inclusive',
            'periodicity' => 'monthly', 'starts_on' => '2030-01-01', 'ends_on' => null, 'trial' => ['type' => 'trial', 'starts_on' => '2030-01-01', 'ends_on' => '2030-01-31'], 'exceptions' => [], 'reason' => 'Boundary test']);
    }

    private function platform(): static
    {
        return $this->withToken($this->operatorToken);
    }

    public function test_usage_counts_each_actual_role_and_active_pending_expired_invitations_only(): void
    {
        $institution = Institution::factory()->create();
        $multi = $this->member($institution, ['admin', 'editor']);
        $pending = $this->member($institution, ['reader'], ['password' => null, 'email_verified_at' => null]);
        [$invitation] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($pending));
        $invitation->update(['expires_at' => now()->subDay()]);
        $this->member($institution, ['admin', 'reader'], ['active' => false]);
        $this->member($institution, ['reader'])->delete();
        $other = Institution::factory()->create();
        $this->member($other, ['reader']);
        $this->configure($institution, ['admin' => 1, 'editor' => 1, 'reader' => 1]);
        $response = $this->platform()->getJson('/api/v1/platform/institutions/'.$institution->id.'/quotas')->assertOk();
        foreach (['admin', 'editor', 'reader'] as $role) {
            $response->assertJsonPath('data.roles.'.$role.'.used', 1);
        }
        $this->assertCount(2, $multi->fresh()->roles);
        $this->assertDatabaseMissing('institutions', ['id' => $institution->id, 'status' => false]);
    }

    public function test_legacy_creation_invitation_and_combined_reactivation_cannot_bypass_quota(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $inactive = $this->member($institution, ['reader'], ['active' => false]);
        $this->configure($institution, ['admin' => 1, 'editor' => 0, 'reader' => 0]);
        $this->withToken($admin->createToken('fixture')->plainTextToken);
        $before = User::count();
        $this->postJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'name' => 'Legacy fixture', 'email' => 'legacy-boundary@example.test', 'password' => 'Fixture-password-123', 'password_confirmation' => 'Fixture-password-123', 'rol' => 'reader'])
            ->assertConflict()->assertJsonPath('error.details.requested_delta', 1);
        $this->postJson('/api/v1/institution/user-invitations', ['institution_id' => $institution->id, 'name' => 'Invited fixture', 'email' => 'invitation-boundary@example.test', 'role' => 'editor'])
            ->assertConflict()->assertJsonPath('error.code', 'USER_QUOTA_EXCEEDED');
        $this->patchJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'email' => $inactive->email, 'active' => true, 'role' => 'editor'])
            ->assertConflict()->assertJsonPath('error.details.role', 'editor');
        $this->assertSame($before, User::count());
        $this->assertFalse($inactive->fresh()->active);
        $this->assertTrue($inactive->fresh()->roles()->where('type', 'reader')->exists());
        $this->assertDatabaseCount('user_invitations', 0);
        Bus::assertNothingDispatched();
    }

    public function test_lowering_limits_preserves_users_and_allows_only_nonincreasing_operations(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $reader = $this->member($institution, ['reader']);
        $pending = $this->member($institution, ['reader'], ['email_verified_at' => null, 'password' => null]);
        $this->configure($institution, ['admin' => 0, 'editor' => null, 'reader' => 0]);
        $this->platform()->getJson('/api/v1/platform/institutions/'.$institution->id.'/quotas')->assertOk()
            ->assertJsonPath('data.roles.reader.over_by', 2)->assertJsonPath('data.roles.admin.over_by', 1);
        $this->withToken($admin->createToken('fixture')->plainTextToken);
        $this->patchJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'email' => $reader->email, 'name' => 'Profile edit'])->assertOk();
        $this->postJson('/api/v1/institution/users/'.$pending->id.'/invitation')->assertStatus(202);
        $this->patchJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'email' => $reader->email, 'role' => 'editor'])->assertOk();
        $this->assertTrue($reader->fresh()->active);
        $this->assertTrue($pending->fresh()->active);
        $this->assertTrue($admin->fresh()->active);
    }

    public function test_last_usable_admin_is_protected_for_self_and_other_targets_but_zero_admin_can_be_repaired(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $pending = $this->member($institution, ['admin'], ['email_verified_at' => null, 'password' => null]);
        foreach ([$admin, $pending] as $actor) {
            foreach ([['active' => false], ['role' => 'editor'], ['active' => false, 'role' => 'reader']] as $data) {
                try {
                    app(InstitutionUserMutations::class)->update($actor, $admin->email, $data);
                    $this->fail('Last usable admin removed.');
                } catch (InstitutionAccessException $e) {
                    $this->assertSame('INSTITUTION_REQUIRES_USABLE_ADMIN', $e->errorCode);
                }
            }
            try {
                app(InstitutionUserMutations::class)->delete($actor, $admin->email);
                $this->fail();
            } catch (InstitutionAccessException $e) {
                $this->assertSame('INSTITUTION_REQUIRES_USABLE_ADMIN', $e->errorCode);
            }
        }
        $zero = Institution::factory()->create();
        $inactive = $this->member($zero, ['admin'], ['active' => false]);
        $this->platform()->postJson('/api/v1/platform/institutions/'.$zero->id.'/administrators/'.$inactive->id.'/reactivation', ['expected_user_revision' => 0, 'reason' => 'Repair zero usable admins'])
            ->assertOk()->assertJsonPath('data.usable_administrator', true);
        $this->assertTrue($admin->fresh()->active);
    }

    public function test_suspension_revokes_credentials_and_links_and_reactivation_restores_no_old_access(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $pending = $this->member($institution, ['admin'], ['email_verified_at' => null, 'password' => null]);
        $inactive = $this->member($institution, ['reader'], ['active' => false]);
        $token = $admin->createToken('old')->plainTextToken;
        $reset = Password::broker()->createToken($admin);
        [$invitation, $activation] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($pending));
        DB::table('sessions')->insert(['id' => 'fixture-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        $state = [$admin->password, $pending->email_verified_at, $inactive->active];
        $this->platform()->postJson('/api/v1/platform/institutions/'.$institution->id.'/suspension', ['expected_revision' => 0, 'reason' => 'Suspend fixture'])->assertOk()->assertJsonPath('data.operational_revision', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1); // platform token survives
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->withToken($token)->getJson('/api/v1/user/profile')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('error.code', 'INSTITUTION_SUSPENDED');
        $this->postJson('/api/v1/auth/password-recovery', ['email' => $admin->email])->assertStatus(202);
        $this->platform()->getJson('/api/v1/platform/institutions/'.$institution->id.'/administrators/'.$pending->id.'/invitation')->assertOk();
        $this->platform()->postJson('/api/v1/platform/institutions/'.$institution->id.'/administrators/'.$pending->id.'/invitation', ['reason' => 'Denied resend'])->assertForbidden();
        $this->platform()->postJson('/api/v1/platform/institutions/'.$institution->id.'/reactivation', ['expected_revision' => 1, 'reason' => 'Reactivate fixture'])->assertOk();
        $this->assertSame(2, (int) $institution->fresh()->auth_epoch);
        $this->assertSame($state, [$admin->fresh()->password, $pending->fresh()->email_verified_at, $inactive->fresh()->active]);
        $this->withToken($token)->getJson('/api/v1/user/profile')->assertUnauthorized();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/auth/activation', ['email' => $pending->email, 'token' => $activation, 'password' => 'Fixture-password-123', 'password_confirmation' => 'Fixture-password-123'])->assertStatus(400);
        $this->postJson('/api/v1/auth/password-reset', ['email' => $admin->email, 'token' => $reset, 'password' => 'Fixture-password-123', 'password_confirmation' => 'Fixture-password-123'])->assertStatus(400);
        (new SendUserInvitation($invitation->id, $invitation->generation, $activation))->handle();
        (new SendPasswordRecovery($admin->id, $reset))->handle();
        Mail::assertNothingSent();
    }

    public function test_repair_is_scoped_revision_checked_and_quota_checked_without_promotion_or_activation(): void
    {
        $institution = Institution::factory()->create();
        $inactive = $this->member($institution, ['admin', 'editor'], ['active' => false, 'password' => null, 'email_verified_at' => null]);
        $reader = $this->member($institution, ['reader']);
        $foreign = $this->member(Institution::factory()->create());
        $this->configure($institution, ['admin' => 1, 'editor' => 0, 'reader' => null]);
        $path = '/api/v1/platform/institutions/'.$institution->id.'/administrators/';
        $this->platform()->postJson($path.$inactive->id.'/reactivation', ['expected_user_revision' => 1, 'reason' => 'Stale'])->assertConflict();
        $this->platform()->postJson($path.$inactive->id.'/reactivation', ['expected_user_revision' => 0, 'reason' => 'Quota'])->assertConflict()->assertJsonPath('error.details.role', 'editor');
        foreach ([$reader, $foreign] as $user) {
            $this->platform()->getJson($path.$user->id.'/invitation')->assertNotFound();
            $this->platform()->postJson($path.$user->id.'/reactivation', ['expected_user_revision' => 0, 'reason' => 'No promotion'])->assertNotFound();
        }
        $this->configure($institution, ['admin' => 1, 'editor' => 1, 'reader' => null], 1);
        $this->platform()->postJson($path.$inactive->id.'/reactivation', ['expected_user_revision' => 0, 'reason' => 'Repair pending admin'])->assertOk()->assertJsonPath('data.usable_administrator', false);
        $this->assertNull($inactive->fresh()->password);
        $this->assertNull($inactive->fresh()->email_verified_at);
        $this->assertCount(2, $inactive->fresh()->roles);
        $this->platform()->postJson($path.$inactive->id.'/invitation', ['reason' => 'Publish activation'])->assertStatus(202)->assertJsonPath('data.publication_status', 'queued');
        $this->platform()->postJson($path.$inactive->id.'/invitation', ['reason' => 'Throttle'])->assertStatus(429);
    }

    public function test_administrative_audit_failure_rolls_back_suspension_and_repair(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $inactive = $this->member($institution, ['admin'], ['active' => false]);
        $bearer = $admin->createToken('rollback')->plainTextToken;
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->platform()->postJson('/api/v1/platform/institutions/'.$institution->id.'/suspension', ['expected_revision' => 0, 'reason' => 'Rollback'])->assertStatus(500);
        $this->assertTrue($institution->fresh()->status);
        $this->assertSame(0, (int) $institution->fresh()->auth_epoch);
        $this->withToken($bearer)->getJson('/api/v1/user/profile')->assertOk();
        $this->platform()->postJson('/api/v1/platform/institutions/'.$institution->id.'/administrators/'.$inactive->id.'/reactivation', ['expected_user_revision' => 0, 'reason' => 'Rollback'])->assertStatus(500);
        $this->assertFalse($inactive->fresh()->active);
        $this->assertSame(0, (int) $inactive->fresh()->access_revision);
    }

    public function test_institutional_membership_audit_failure_rolls_back_creation_and_activity(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $target = $this->member($institution, ['editor']);
        $this->withToken($admin->createToken('audit fixture')->plainTextToken);
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->postJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'name' => 'Audit fixture', 'email' => 'audit-rollback@example.test', 'password' => 'Fixture-password-123', 'password_confirmation' => 'Fixture-password-123', 'rol' => 'reader'])->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'audit-rollback@example.test']);
        $this->postJson('/api/v1/institution/user-invitations', ['institution_id' => $institution->id, 'name' => 'Audit fixture', 'email' => 'invite-audit-rollback@example.test', 'role' => 'reader'])->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'invite-audit-rollback@example.test']);
        $this->assertDatabaseCount('user_invitations', 0);
        $this->patchJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'email' => $target->email, 'active' => false])->assertStatus(500);
        $this->assertTrue($target->fresh()->active);
        $this->assertSame(0, (int) $target->fresh()->access_revision);
        Bus::assertNothingDispatched();
    }

    public function test_quota_failure_precedes_responsibility_confirmation_and_has_no_document_side_effect(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $editor = $this->member($institution, ['editor']);
        $document = Document::create(['name' => 'Responsibility fixture', 'institution_id' => $institution->id, 'author_id' => $admin->id, 'responsible_user_id' => $editor->id, 'status' => true]);
        $this->configure($institution, ['admin' => null, 'editor' => null, 'reader' => 0]);
        $this->withToken($admin->createToken('quota impact fixture')->plainTextToken);
        $this->patchJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'email' => $editor->email, 'role' => 'reader', 'active' => true])->assertConflict()->assertJsonPath('error.code', 'USER_QUOTA_EXCEEDED');
        $this->assertSame($editor->id, $document->fresh()->responsible_user_id);
        $this->assertDatabaseCount('document_responsible_histories', 0);
        $this->assertDatabaseCount('document_events', 0);
        $this->assertTrue($editor->fresh()->roles()->where('type', 'editor')->exists());
    }

    public function test_suspension_revisions_noops_and_principal_boundaries_are_explicit(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $path = '/api/v1/platform/institutions/'.$institution->id;
        $this->withToken($admin->createToken('institutional')->plainTextToken)->postJson($path.'/suspension', ['expected_revision' => 0, 'reason' => 'Wrong principal'])->assertUnauthorized();
        $unenrolled = $this->operator(false);
        $this->withToken($this->token($unenrolled))->getJson($path.'/quotas')->assertForbidden();
        $this->platform()->postJson($path.'/suspension', ['expected_revision' => 1, 'reason' => 'Stale'])->assertConflict();
        $this->platform()->postJson($path.'/suspension', ['expected_revision' => 0, 'reason' => 'Suspend'])->assertOk();
        $this->platform()->postJson($path.'/suspension', ['expected_revision' => 1, 'reason' => 'Noop'])->assertOk()->assertJsonPath('data.operational_revision', 1);
        $this->assertSame(1, DB::table('administrative_events')->where('action', 'institution.suspended')->count());
        $this->platform()->postJson($path.'/reactivation', ['expected_revision' => 0, 'reason' => 'Stale'])->assertConflict();
        $this->platform()->postJson($path.'/reactivation', ['expected_revision' => 1, 'reason' => 'Reactivate', 'status' => true])->assertUnprocessable();
    }

    public function test_protected_patch_rejects_credential_and_tenant_fields(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution);
        $this->withToken($admin->createToken('strict input')->plainTextToken);
        foreach (['password', 'email_verified_at', 'deleted_at', 'access_revision', 'auth_epoch', 'remember_token'] as $field) {
            $this->patchJson('/api/v1/institution/users', ['institution_id' => $institution->id, 'email' => $admin->email, $field => 'synthetic'])->assertUnprocessable();
        }
        $this->assertSame(0, (int) $admin->fresh()->access_revision);
    }
}
