<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\InstitutionContractRevision;
use App\Platform\Services\AdministrativeEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentResponsibleHistory;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;

class InstitutionalRoleManagementTest extends PlatformTestCase
{
    private $operator;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate(['type' => $role]);
        }
        $this->operator = $this->operator();
        $this->withToken($this->token($this->operator));
    }

    private function member(Institution $institution, array $roles): User
    {
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::whereIn('type', $roles)->pluck('id'));

        return $user;
    }

    private function change(User $user, array $body, string $action = 'role')
    {
        return $this->postJson('/api/v1/platform/institutions/'.$user->institution_id.'/users/'.$user->id.'/'.$action,
            $body + ['expected_user_revision' => (int) $user->fresh()->access_revision, 'reason' => 'Reviewed support correction', 'confirmation' => true]);
    }

    public function test_promotion_preserves_identity_activity_and_password_and_revokes_credentials(): void
    {
        $user = $this->member(Institution::factory()->create(), ['editor']);
        $password = $user->password;
        $verified = $user->email_verified_at;
        $user->createToken('old');
        $this->change($user, ['role' => 'admin'])->assertOk()->assertJsonPath('data.roles', ['admin']);
        $this->assertTrue($user->fresh()->active);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertEquals($verified, $user->fresh()->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('administrative_events', ['actor_kind' => 'platform', 'actor_id' => $this->operator->id, 'action' => 'user.role_changed']);
    }

    public function test_last_admin_cannot_be_demoted_or_deactivated_but_another_admin_allows_demotion(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->member($institution, ['admin']);
        $this->change($user, ['role' => 'editor'])->assertConflict()->assertJsonPath('error.code', 'INSTITUTION_REQUIRES_USABLE_ADMIN');
        $this->change($user, ['active' => false], 'access')->assertConflict();
        $this->member($institution, ['admin']);
        $this->change($user, ['role' => 'editor'])->assertOk();
        $this->assertTrue($user->fresh()->active);
        $this->assertSame(['editor'], $user->roles()->pluck('type')->map(fn ($r) => $r->value)->all());
    }

    public function test_reader_transition_requires_fresh_impact_and_records_actual_platform_actor(): void
    {
        $user = $this->member(Institution::factory()->create(), ['editor']);
        $node = Node::factory()->create(['institution_id' => $user->institution_id]);
        $document = Document::create(['name' => 'Responsibility fixture', 'status' => true, 'author_id' => $user->id, 'institution_id' => $user->institution_id, 'node_id' => $node->id, 'responsible_user_id' => $user->id]);
        $path = '/api/v1/platform/institutions/'.$user->institution_id.'/users/'.$user->id.'/role-impact?role=reader';
        $impact = $this->getJson($path)->assertOk()->assertJsonPath('data.affected_documents_count', 1)->json('data');
        $this->change($user, ['role' => 'reader'])->assertConflict();
        $document->increment('responsibility_revision');
        $this->change($user, ['role' => 'reader', 'confirm_responsibility_removal' => true, 'role_impact_token' => $impact['impact_token']])->assertConflict();
        $impact = $this->getJson($path)->assertOk()->json('data');
        $this->change($user, ['role' => 'reader', 'confirm_responsibility_removal' => true, 'role_impact_token' => $impact['impact_token']])->assertOk();
        $this->assertNull($document->fresh()->responsible_user_id);
        $history = DocumentResponsibleHistory::where('document_id', $document->id)->firstOrFail();
        $this->assertNull($history->actor_user_id);
        $this->assertSame($this->operator->id, $history->actor_platform_operator_id);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'actor_user_id' => null, 'actor_platform_operator_id' => $this->operator->id]);
    }

    public function test_activity_changes_preserve_roles_and_roleless_users_remain_roleless(): void
    {
        foreach ([['editor'], []] as $roles) {
            $user = $this->member(Institution::factory()->create(), $roles);
            $this->change($user, ['active' => false], 'access')->assertOk()->assertJsonPath('data.roles', $roles);
            $this->change($user, ['active' => true], 'access')->assertOk()->assertJsonPath('data.roles', $roles);
            $this->assertSame(count($roles), $user->roles()->count());
        }
    }

    public function test_role_assignment_does_not_activate_or_verify_a_roleless_pending_account(): void
    {
        $user = $this->member(Institution::factory()->create(), []);
        $user->forceFill(['active' => false, 'email_verified_at' => null])->save();
        $password = $user->password;
        $this->change($user, ['role' => 'admin'])->assertOk()->assertJsonPath('data.active', false);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertFalse($user->fresh()->active);
    }

    public function test_stale_revision_suspension_permission_and_tenant_boundaries(): void
    {
        $user = $this->member(Institution::factory()->create(), ['reader']);
        $this->change($user, ['role' => 'admin', 'expected_user_revision' => 999])->assertConflict();
        $other = Institution::factory()->create();
        $this->postJson('/api/v1/platform/institutions/'.$other->id.'/users/'.$user->id.'/role', ['role' => 'admin', 'expected_user_revision' => 0, 'reason' => 'fixture', 'confirmation' => true])->assertNotFound();
        $user->institution->update(['status' => false]);
        $this->change($user, ['role' => 'admin'])->assertForbidden();
        $user->institution->update(['status' => true]);
        Gate::define('platform.user_access', fn () => false);
        $this->change($user, ['role' => 'admin'])->assertForbidden();
        $this->assertSame(['reader'], $user->roles()->pluck('type')->map(fn ($r) => $r->value)->all());
    }

    public function test_multiple_role_inventory_is_read_only_and_explicit_regularization_uses_normal_policy(): void
    {
        $user = $this->member(Institution::factory()->create(), ['reader', 'editor']);
        $before = DB::table('role_user')->get()->toJson();
        $count = AdministrativeEvent::count();
        $this->artisan('platform:inspect-institutional-roles')->expectsOutputToContain($user->id)->assertSuccessful();
        $this->assertSame($before, DB::table('role_user')->get()->toJson());
        $this->assertSame($count, AdministrativeEvent::count());
        $this->change($user, ['role' => 'editor'])->assertOk()->assertJsonPath('data.roles', ['editor']);
    }

    public function test_required_audit_failure_rolls_back_membership_and_revocation(): void
    {
        $user = $this->member(Institution::factory()->create(), ['reader']);
        $user->createToken('retained');
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('synthetic audit failure'));
        $this->change($user, ['role' => 'admin'])->assertStatus(500);
        $this->assertSame(['reader'], $user->roles()->pluck('type')->map(fn ($r) => $r->value)->all());
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_history_failure_rolls_back_role_responsibility_and_access(): void
    {
        $user = $this->member(Institution::factory()->create(), ['editor']);
        $document = Document::create(['name' => 'Rollback fixture', 'status' => true, 'author_id' => $user->id, 'institution_id' => $user->institution_id, 'responsible_user_id' => $user->id]);
        $impact = $this->getJson('/api/v1/platform/institutions/'.$user->institution_id.'/users/'.$user->id.'/role-impact?role=reader')->assertOk()->json('data');
        DB::unprepared("CREATE TRIGGER fail_platform_role_history BEFORE INSERT ON document_responsible_histories BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->change($user, ['role' => 'reader', 'confirm_responsibility_removal' => true, 'role_impact_token' => $impact['impact_token']])->assertStatus(500);
        $this->assertSame($user->id, $document->fresh()->responsible_user_id);
        $this->assertSame(['editor'], $user->roles()->pluck('type')->map(fn ($r) => $r->value)->all());
        $this->assertSame(0, (int) $user->fresh()->access_revision);
        $this->assertDatabaseCount('document_events', 0);
        $this->assertDatabaseCount('document_responsible_histories', 0);
    }

    public function test_quotas_release_previous_role_and_rejection_rolls_back_without_revocation(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->member($institution, ['editor']);
        $contract = InstitutionContract::create(['institution_id' => $institution->id]);
        $revision = InstitutionContractRevision::create(['contract_id' => $contract->id, 'institution_id' => $institution->id,
            'revision' => 1, 'effective_at' => now(), 'conditions' => ['limits' => ['admin' => 0, 'editor' => 1, 'reader' => 0]],
            'actor_id' => $this->operator->id, 'operation_id' => (string) Str::uuid()]);
        $contract->update(['revision' => 1, 'current_revision_id' => $revision->id]);
        $user->createToken('retained');
        $this->change($user, ['role' => 'admin'])->assertConflict()->assertJsonPath('error.code', 'USER_QUOTA_EXCEEDED');
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(0, (int) $user->fresh()->access_revision);
        $this->assertDatabaseMissing('administrative_events', ['subject_id' => $user->id, 'action' => 'user.role_changed']);
        $next = InstitutionContractRevision::create(['contract_id' => $contract->id, 'institution_id' => $institution->id,
            'revision' => 2, 'effective_at' => now(), 'conditions' => ['limits' => ['admin' => 1, 'editor' => 1, 'reader' => 0]],
            'actor_id' => $this->operator->id, 'operation_id' => (string) Str::uuid()]);
        $contract->update(['revision' => 2, 'current_revision_id' => $next->id]);
        $this->change($user, ['role' => 'admin'])->assertOk();
        $report = $this->getJson('/api/v1/platform/institutions/'.$institution->id.'/quotas')->assertOk();
        $report->assertJsonPath('data.roles.editor.used', 0)->assertJsonPath('data.roles.admin.used', 1);
        // Reactivation preserves membership and must acquire the admin seat again.
        $this->member($institution, ['admin']);
        $this->change($user, ['active' => false], 'access')->assertOk();
        $this->change($user, ['active' => true], 'access')->assertConflict()->assertJsonPath('error.code', 'USER_QUOTA_EXCEEDED');
        $this->assertFalse($user->fresh()->active);
        $this->assertSame(['admin'], $user->roles()->pluck('type')->map(fn ($r) => $r->value)->all());
    }
}
