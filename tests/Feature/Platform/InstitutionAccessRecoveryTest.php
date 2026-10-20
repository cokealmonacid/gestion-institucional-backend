<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Jobs\SendInstitutionRecoveryProof;
use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\InstitutionContractRevision;
use App\Platform\Models\PlatformAccessRecovery;
use App\Platform\Services\InstitutionAccessRecovery;
use App\Services\UserAccess\InvitationService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Nodes\Models\Node;
use RuntimeException;

class InstitutionAccessRecoveryTest extends PlatformTestCase
{
    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        $this->bearer = $this->token($this->operator());
        $this->withToken($this->bearer);
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate(['type' => $role]);
        }
    }

    private function member(Institution $institution, array $roles = ['admin'], array $attributes = []): User
    {
        $user = User::factory()->for($institution)->create($attributes);
        $user->roles()->attach(Rol::whereIn('type', $roles)->pluck('id'));

        return $user;
    }

    private function limits(Institution $institution, array $limits): void
    {
        $contract = InstitutionContract::firstOrCreate(['institution_id' => $institution->id]);
        $revision = InstitutionContractRevision::create(['contract_id' => $contract->id, 'institution_id' => $institution->id,
            'revision' => $contract->revision + 1, 'effective_at' => now(), 'conditions' => ['limits' => $limits], 'actor_id' => Str::uuid(), 'operation_id' => Str::uuid()]);
        $contract->update(['revision' => $revision->revision, 'current_revision_id' => $revision->id]);
    }

    private function body(User $target, string $kind = 'replacement', array $extra = []): array
    {
        return ['kind' => $kind, 'institution_id' => $target->institution_id, 'target_id' => $target->id,
            'expected_target_revision' => (int) $target->access_revision, 'expected_institution_revision' => (int) $target->institution->operational_revision,
            'authority' => ['requester' => 'Representative fixture', 'relationship' => 'Authorized director', 'channel' => 'Verified callback', 'reference' => 'Synthetic ticket'],
            'confirmation' => true, 'reason' => 'Synthetic authorized recovery'] + $extra;
    }

    private function prepare(array $body, ?string $key = null): array
    {
        return $this->withToken($this->bearer)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/platform/access-recoveries', $body)->assertCreated()->json('data');
    }

    private function proof(array $operation, bool $new = false): array
    {
        $job = Bus::dispatched(SendInstitutionRecoveryProof::class)->last();
        $this->assertNotNull($job);
        $this->postJson('/api/v1/platform/auth/access-recovery-verification', ['operation_id' => $operation['id'], 'proof' => $job->proof]
            + ($new ? ['password' => 'Synthetic-successor-password-123', 'password_confirmation' => 'Synthetic-successor-password-123'] : []))
            ->assertOk()->assertJsonPath('data.access_granted', false);

        return $this->getJson('/api/v1/platform/access-recoveries/'.$operation['id'])->assertOk()->json('data');
    }

    private function complete(array $operation)
    {
        return $this->postJson('/api/v1/platform/access-recoveries/'.$operation['id'].'/complete', ['expected_revision' => $operation['revision'], 'confirmation' => true]);
    }

    public function test_new_successor_replaces_at_full_quota_and_preserves_history_responsibilities_and_credentials_are_revoked(): void
    {
        $institution = Institution::factory()->create();
        $outgoing = $this->member($institution);
        $this->limits($institution, ['admin' => 1, 'editor' => 0, 'reader' => 0]);
        $old = $outgoing->createToken('fixture')->plainTextToken;
        Password::broker()->createToken($outgoing);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $outgoing->id, 'payload' => '', 'last_activity' => time()]);
        [$invitation] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($outgoing));
        $outgoing->refresh();
        $node = Node::factory()->for($institution)->create();
        $document = Document::create(['name' => 'Synthetic responsibility', 'status' => true, 'author_id' => $outgoing->id, 'node_id' => $node->id, 'institution_id' => $institution->id, 'responsible_user_id' => $outgoing->id]);
        $before = $document->fresh()->getAttributes();
        $operation = $this->prepare($this->body($outgoing, extra: ['successor_name' => 'New authorized person', 'successor_email' => 'new-person@example.test']));
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($outgoing->fresh()->active);
        $verified = $this->proof($operation, true);
        $this->assertDatabaseCount('users', 1);
        $result = $this->complete($verified)->assertOk()->assertJsonPath('data.state', 'completed')->json('data.result');
        $successor = User::findOrFail($result['successor_id']);
        $this->assertTrue($successor->active);
        $this->assertTrue(Hash::check('Synthetic-successor-password-123', $successor->password));
        $this->assertFalse($outgoing->fresh()->active);
        $this->assertSame(['admin'], $outgoing->fresh()->roles->pluck('type')->map(fn ($r) => $r->value)->all());
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertSame(0, $outgoing->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $outgoing->email]);
        $this->assertNull($invitation->fresh()->token_hash);
        $this->assertNull(PlatformAccessRecovery::find($operation['id'])->prepared_password);
        $this->complete($verified)->assertOk()->assertJsonPath('data.result.successor_id', $successor->id);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(1, AdministrativeEvent::where('action', 'access_recovery.complete')->count());
        $this->withToken($old)->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_existing_successor_keeps_preparation_access_then_becomes_admin_only_with_existing_excess(): void
    {
        $institution = Institution::factory()->create();
        $outgoing = $this->member($institution);
        $successor = $this->member($institution, ['editor', 'reader']);
        $password = $successor->password;
        $token = $successor->createToken('fixture')->plainTextToken;
        $this->limits($institution, ['admin' => 0, 'editor' => 0, 'reader' => 0]);
        $operation = $this->prepare($this->body($outgoing, extra: ['successor_id' => $successor->id]));
        $this->assertSame(1, $successor->tokens()->count());
        $this->assertSame($password, $successor->fresh()->password);
        $this->assertSame(2, $successor->roles()->count());
        $verified = $this->proof($operation);
        $this->assertSame(2, $successor->roles()->count());
        $this->assertSame(1, $successor->tokens()->count());
        $result = $this->complete($verified)->assertOk()->json('data.result');
        $this->assertSame(['admin'], $result['successor_roles']);
        $this->assertSame(1, $successor->roles()->count());
        $this->assertSame(0, $result['quotas']['roles']['editor']['used']);
        $this->assertSame(0, $result['quotas']['roles']['reader']['used']);
        $this->assertSame($password, $successor->fresh()->password);
        $this->assertSame(1, $result['quotas']['roles']['admin']['over_by']);
        $this->assertSame(0, $successor->tokens()->count());
        $this->withToken($token)->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_editor_and_reader_successors_remain_unchanged_until_verified_completion_then_have_only_admin(): void
    {
        foreach (['editor', 'reader'] as $role) {
            $institution = Institution::factory()->create();
            $target = $this->member($institution);
            $successor = $this->member($institution, [$role]);
            $this->limits($institution, ['admin' => 1, 'editor' => 1, 'reader' => 1]);
            $operation = $this->prepare($this->body($target, extra: ['successor_id' => $successor->id]));
            $verified = $this->proof($operation);
            $this->assertSame([$role], $successor->roles()->pluck('type')->map(fn ($r) => $r->value)->all());
            $this->assertTrue($successor->fresh()->active);
            $result = $this->complete($verified)->assertOk()->json('data.result');
            $this->assertSame(['admin'], $result['successor_roles']);
            $this->assertSame(0, $result['quotas']['roles'][$role]['used']);
            $this->assertSame(1, $successor->roles()->count());
        }
    }

    public function test_suspended_institution_and_inactive_outgoing_are_supported_without_granting_access(): void
    {
        $institution = Institution::factory()->create(['status' => false]);
        $outgoing = $this->member($institution, attributes: ['active' => false]);
        $this->limits($institution, ['admin' => 1, 'editor' => null, 'reader' => null]);
        $operation = $this->prepare($this->body($outgoing, extra: ['successor_name' => 'Successor', 'successor_email' => 'suspended-successor@example.test']));
        $result = $this->complete($this->proof($operation, true))->assertOk()->assertJsonPath('data.result.institution_suspended', true)->assertJsonPath('data.result.outgoing_already_inactive', true)->json('data.result');
        $this->assertFalse($institution->fresh()->status);
        $this->postJson('/api/v1/auth/login', ['email' => User::find($result['successor_id'])->email, 'password' => 'Synthetic-successor-password-123'])->assertForbidden();
    }

    public function test_containment_of_sole_admin_is_explicit_audited_and_preserves_roles(): void
    {
        $target = $this->member(Institution::factory()->create());
        $target->createToken('fixture');
        $operation = $this->prepare($this->body($target, 'containment'));
        $this->assertSame('completed', $operation['state']);
        $this->assertTrue($operation['result']['recovery_required']);
        $this->assertFalse($target->fresh()->active);
        $this->assertSame(1, $target->roles()->count());
        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue($target->institution->fresh()->status);
        $this->assertDatabaseHas('administrative_events', ['action' => 'access_recovery.contained', 'actor_kind' => 'platform']);
        Bus::assertNothingDispatched();
    }

    public function test_email_correction_verifies_before_switch_preserves_identity_password_activity_and_roles_and_revokes_old_proofs(): void
    {
        $institution = Institution::factory()->create(['status' => false]);
        $target = $this->member($institution, ['editor', 'reader'], ['active' => false]);
        $password = $target->password;
        $oldEmail = $target->email;
        Password::broker()->createToken($target);
        $operation = $this->prepare($this->body($target, 'email_correction', ['new_email' => 'corrected@example.test', 'same_person' => true]));
        $this->assertSame($oldEmail, $target->fresh()->email);
        $verified = $this->proof($operation);
        $this->complete($verified)->assertOk()->assertJsonPath('data.result.recovery_eligible', false);
        $target->refresh();
        $this->assertSame('corrected@example.test', $target->email);
        $this->assertSame($password, $target->password);
        $this->assertFalse($target->active);
        $this->assertSame(2, $target->roles()->count());
        $this->assertFalse($institution->fresh()->status);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    }

    public function test_invalid_expired_replayed_cancelled_and_rotated_proofs_never_grant_access(): void
    {
        $target = $this->member(Institution::factory()->create());
        $operation = $this->prepare($this->body($target, 'email_correction', ['new_email' => 'proof@example.test', 'same_person' => true]));
        $job = Bus::dispatched(SendInstitutionRecoveryProof::class)->last();
        $url = '/api/v1/platform/auth/access-recovery-verification';
        $this->postJson($url, ['operation_id' => $operation['id'], 'proof' => 'invalid'])->assertStatus(400);
        $this->complete($operation)->assertConflict();
        $this->travel(61)->seconds();
        $resent = $this->postJson('/api/v1/platform/access-recoveries/'.$operation['id'].'/resend', ['expected_revision' => 1, 'confirmation' => true, 'reason' => 'Explicit retry'])->assertOk()->json('data');
        $this->postJson($url, ['operation_id' => $operation['id'], 'proof' => $job->proof])->assertStatus(400);
        $verified = $this->proof($resent);
        $this->postJson($url, ['operation_id' => $operation['id'], 'proof' => Bus::dispatched(SendInstitutionRecoveryProof::class)->last()->proof])->assertStatus(400);
        $this->postJson('/api/v1/platform/access-recoveries/'.$operation['id'].'/cancel', ['expected_revision' => $verified['revision'], 'confirmation' => true, 'reason' => 'Cancel fixture'])->assertOk();
        $this->complete($verified)->assertConflict();
        $next = $this->prepare($this->body($target, 'email_correction', ['new_email' => 'proof@example.test', 'same_person' => true]));
        $this->travel(61)->minutes();
        $this->withToken($this->token($this->operator()));
        $this->getJson('/api/v1/platform/access-recoveries/'.$next['id'])->assertJsonPath('data.state', 'expired');
        $this->postJson($url, ['operation_id' => $next['id'], 'proof' => Bus::dispatched(SendInstitutionRecoveryProof::class)->last()->proof])->assertStatus(400);
    }

    public function test_stale_user_institution_agreement_and_competing_replacement_are_rejected(): void
    {
        $institution = Institution::factory()->create();
        $target = $this->member($institution);
        $candidate = $this->member($institution, ['reader']);
        $first = $this->prepare($this->body($target, extra: ['successor_id' => $candidate->id]));
        $first = $this->proof($first);
        $other = $this->prepare($this->body($target, extra: ['successor_name' => 'Other person', 'successor_email' => 'competing@example.test']));
        $other = $this->proof($other, true);
        $this->complete($first)->assertOk();
        $this->complete($other)->assertConflict()->assertJsonPath('error.code', 'RECOVERY_STALE');
        $this->assertDatabaseCount('users', 2);
        $operation = $this->prepare($this->body($target->fresh(), 'email_correction', ['new_email' => 'stale@example.test', 'same_person' => true]));
        $operation = $this->proof($operation);
        $this->limits($institution, ['admin' => 0, 'editor' => 0, 'reader' => 0]);
        $this->complete($operation)->assertConflict()->assertJsonPath('error.code', 'RECOVERY_STALE');
    }

    public function test_email_reservations_and_final_user_uniqueness_including_deleted_records(): void
    {
        $target = $this->member(Institution::factory()->create());
        $other = $this->member(Institution::factory()->create());
        $body = $this->body($target, 'email_correction', ['new_email' => 'reserved@example.test', 'same_person' => true]);
        $operation = $this->prepare($body);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/platform/access-recoveries', $this->body($other, 'email_correction', ['new_email' => 'reserved@example.test', 'same_person' => true]))
            ->assertConflict()->assertJsonPath('error.code', 'EMAIL_UNAVAILABLE');
        $verified = $this->proof($operation);
        User::factory()->create(['email' => 'reserved@example.test'])->delete();
        $this->complete($verified)->assertConflict()->assertJsonPath('error.code', 'EMAIL_UNAVAILABLE');
        $this->assertSame($body['target_id'], $target->id);
    }

    public function test_required_audit_failure_rolls_back_completion_and_proof_consumption(): void
    {
        $target = $this->member(Institution::factory()->create());
        $operation = $this->prepare($this->body($target, extra: ['successor_name' => 'New person', 'successor_email' => 'rollback@example.test']));
        $verified = $this->proof($operation, true);
        AdministrativeEvent::creating(function ($event) {
            if ($event->action === 'access_recovery.complete') {
                throw new RuntimeException('Synthetic audit outage');
            }
        });
        try {
            $this->complete($verified)->assertServerError();
            $this->assertTrue($target->fresh()->active);
            $this->assertDatabaseCount('users', 1);
            $this->assertSame('verified', PlatformAccessRecovery::find($operation['id'])->state);
        } finally {
            AdministrativeEvent::flushEventListeners();
        }
    }

    public function test_preparation_is_idempotent_recoverable_by_authorized_operator_and_mail_failure_does_not_undo_it(): void
    {
        Bus::swap(\Mockery::mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Synthetic transport'))->getMock());
        $target = $this->member(Institution::factory()->create());
        $body = $this->body($target, 'email_correction', ['new_email' => 'lost-response@example.test', 'same_person' => true]);
        $key = (string) Str::uuid();
        $first = $this->prepare($body, $key);
        $again = $this->prepare($body, $key);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('failed', $again['publication_status']);
        $this->assertDatabaseCount('platform_access_recoveries', 1);
        $this->withToken($this->token($this->operator()))->getJson('/api/v1/platform/access-recoveries/'.$key)->assertOk();
        $this->getJson('/api/v1/platform/access-recoveries?institution_id='.$target->institution_id)->assertJsonPath('data.total', 1);
        Mail::assertNothingSent();
    }

    public function test_platform_permission_and_institution_isolation_and_explicit_authority_are_required(): void
    {
        $target = $this->member(Institution::factory()->create());
        $foreign = $this->member(Institution::factory()->create(), ['reader']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/platform/access-recoveries', $this->body($target, extra: ['successor_id' => $foreign->id]))->assertNotFound();
        $body = $this->body($target, 'containment');
        unset($body['authority']);
        $this->postJson('/api/v1/platform/access-recoveries', $body)->assertUnprocessable();
        $this->withToken($target->createToken('tenant', ['*'])->plainTextToken)->getJson('/api/v1/platform/access-recoveries')->assertUnauthorized();
        $this->withToken($this->bearer);
        Gate::define('platform.access_recovery', fn () => false);
        $this->postJson('/api/v1/platform/access-recoveries', $this->body($target, 'containment'))->assertForbidden();
        $this->assertTrue($target->fresh()->active);
    }

    public function test_inactive_legacy_multirole_successor_releases_old_roles_instead_of_consuming_their_quotas(): void
    {
        $institution = Institution::factory()->create();
        $target = $this->member($institution);
        $successor = $this->member($institution, ['editor', 'reader'], ['active' => false]);
        $this->limits($institution, ['admin' => 1, 'editor' => 0, 'reader' => 0]);
        $operation = $this->proof($this->prepare($this->body($target, extra: ['successor_id' => $successor->id])));
        $this->complete($operation)->assertOk()->assertJsonPath('data.result.successor_roles', ['admin']);
        $this->assertFalse($target->fresh()->active);
        $this->assertTrue($successor->fresh()->active);
        $this->assertSame(1, $successor->roles()->count());
    }

    public function test_current_user_and_institution_revisions_are_rechecked_without_changing_existing_password(): void
    {
        foreach (['user', 'institution'] as $changed) {
            $target = $this->member(Institution::factory()->create());
            $operation = $this->proof($this->prepare($this->body($target, 'email_correction', ['new_email' => $changed.'-revision@example.test', 'same_person' => true])));
            if ($changed === 'user') {
                DB::transaction(fn () => app(InstitutionalAccess::class)->revoke($target));
            } else {
                $target->institution->increment('operational_revision');
            }
            $this->complete($operation)->assertConflict()->assertJsonPath('error.code', 'RECOVERY_STALE');
            $this->assertSame('verified', PlatformAccessRecovery::find($operation['id'])->state);
        }
    }

    public function test_verification_audit_failure_preserves_unconsumed_proof_and_no_account_changes(): void
    {
        $target = $this->member(Institution::factory()->create());
        $operation = $this->prepare($this->body($target, 'email_correction', ['new_email' => 'audit-proof@example.test', 'same_person' => true]));
        $job = Bus::dispatched(SendInstitutionRecoveryProof::class)->last();
        AdministrativeEvent::creating(function ($event) {
            if ($event->action === 'access_recovery.address_verified') {
                throw new RuntimeException('Synthetic required audit failure');
            }
        });
        try {
            $this->postJson('/api/v1/platform/auth/access-recovery-verification', ['operation_id' => $operation['id'], 'proof' => $job->proof])->assertServerError();
            $record = PlatformAccessRecovery::find($operation['id']);
            $this->assertSame('awaiting_verification', $record->state);
            $this->assertTrue(Hash::check($job->proof, $record->verification_hash));
            $this->assertNotSame('audit-proof@example.test', $target->fresh()->email);
        } finally {
            AdministrativeEvent::flushEventListeners();
        }
    }

    public function test_nested_outer_rollback_never_publishes_recovery_proof(): void
    {
        $target = $this->member(Institution::factory()->create());
        $actor = PersonalAccessToken::findToken($this->bearer)->tokenable;
        $actor->withAccessToken(PersonalAccessToken::findToken($this->bearer));
        DB::beginTransaction();
        app(InstitutionAccessRecovery::class)->prepare($actor, (string) Str::uuid(), $this->body($target, 'email_correction', ['new_email' => 'nested@example.test', 'same_person' => true]));
        Bus::assertNothingDispatched();
        DB::rollBack();
        $this->assertDatabaseCount('platform_access_recoveries', 0);
        Bus::assertNothingDispatched();
        DB::beginTransaction();
        app(InstitutionAccessRecovery::class)->prepare($actor, (string) Str::uuid(), $this->body($target, 'email_correction', ['new_email' => 'nested@example.test', 'same_person' => true]));
        DB::commit();
        Bus::assertDispatchedTimes(SendInstitutionRecoveryProof::class, 1);
    }

    public function test_recovery_mail_uses_platform_local_origin_and_cancelled_jobs_do_not_send(): void
    {
        $target = $this->member(Institution::factory()->create(['status' => false]));
        $operation = $this->prepare($this->body($target, 'email_correction', ['new_email' => 'mail-proof@example.test', 'same_person' => true]));
        $job = Bus::dispatched(SendInstitutionRecoveryProof::class)->last();
        config(['app.env' => 'local', 'platform.allow_local_http' => true, 'platform.frontend_url' => 'http://127.0.0.1:8000', 'mail.default' => 'array']);
        Mail::spy();
        $job->handle();
        Mail::shouldHaveReceived('raw')->withArgs(fn ($text, $callback) => str_contains($text, 'http://127.0.0.1:8000/platform/access-verification#operation=') && ! str_contains($text, '/activar-cuenta'))->once();
        $this->getJson('/api/v1/platform/access-recoveries/'.$operation['id'])->assertJsonPath('data.publication_status', 'accepted');
        $this->postJson('/api/v1/platform/access-recoveries/'.$operation['id'].'/cancel', ['expected_revision' => 1, 'confirmation' => true, 'reason' => 'Cancel fixture'])->assertOk();
        Mail::swap(\Mockery::spy(MailManager::class));
        $job->handle();
        Mail::shouldNotHaveReceived('raw');
    }
}
