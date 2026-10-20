<?php

namespace App\Platform\Services;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Jobs\SendInstitutionRecoveryProof;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\PlatformAccessRecovery;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use App\Rules\UserPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Institution\Services\MembershipPolicy;

class InstitutionAccessRecovery
{
    public function __construct(private PlatformAuthentication $auth, private AdministrativeEventRecorder $audit, private MembershipPolicy $policy, private InstitutionalAccess $access) {}

    private function actor(PlatformOperator $actor): PlatformOperator
    {
        $current = $this->auth->authorized($actor);
        Gate::forUser($current)->authorize('platform.access_recovery');

        return $current;
    }

    public function data(PlatformAccessRecovery $operation): array
    {
        $state = $operation->state;
        if (! in_array($state, ['completed', 'cancelled'], true) && $operation->expires_at->lte(now())) {
            $state = 'expired';
        }

        return ['id' => $operation->id, 'kind' => $operation->kind, 'institution_id' => $operation->institution_id,
            'target_id' => $operation->target_id, 'state' => $state, 'revision' => $operation->revision,
            'request' => $operation->request, 'snapshot' => $operation->snapshot, 'result' => $operation->result,
            'publication_status' => $operation->publication_status === 'pending' && $operation->updated_at->lte(now()->subMinutes(5)) ? 'unknown' : $operation->publication_status,
            'expires_at' => $operation->expires_at->toISOString(), 'verified_at' => $operation->verified_at?->toISOString(),
            'created_at' => $operation->created_at->toISOString()];
    }

    private function canonical(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }
        ksort($data);

        return $data;
    }

    public function prepare(PlatformOperator $actor, string $id, array $input): array
    {
        foreach (['new_email', 'successor_email'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = strtolower(trim($input[$field]));
            }
        }
        $input = $this->canonical($input);
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $publication = null;
        try {
            $operation = DB::transaction(function () use ($actor, $id, $input, $fingerprint, &$publication) {
                $publication = null;
                $operator = $this->actor($actor);
                $institution = Institution::whereKey($input['institution_id'])->lockForUpdate()->firstOrFail();
                $old = PlatformAccessRecovery::whereKey($id)->lockForUpdate()->first();
                if ($old) {
                    if ($old->operator_id !== $operator->id || ! hash_equals($old->fingerprint, $fingerprint)) {
                        throw new PlatformException('IDEMPOTENCY_CONFLICT', 409);
                    }

                    return $old;
                }
                $target = User::where('institution_id', $institution->id)->whereKey($input['target_id'])->lockForUpdate()->firstOrFail();
                if ((int) $target->access_revision !== $input['expected_target_revision'] || (int) $institution->operational_revision !== $input['expected_institution_revision']) {
                    throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
                }
                $kind = $input['kind'];
                $successor = null;
                $email = null;
                if ($kind === 'replacement') {
                    if (! $target->roles()->where('type', 'admin')->exists()) {
                        throw new PlatformException('RECOVERY_TARGET_NOT_ADMIN', 409);
                    }
                    if (isset($input['successor_id'])) {
                        $successor = User::where('institution_id', $institution->id)->whereKey($input['successor_id'])->lockForUpdate()->firstOrFail();
                        if ($successor->id === $target->id || ! $successor->email_verified_at || ! is_string($successor->password) || $successor->password === '') {
                            throw new PlatformException('RECOVERY_SUCCESSOR_NOT_READY', 409);
                        }
                    } else {
                        $email = $input['successor_email'];
                    }
                } elseif ($kind === 'email_correction') {
                    $email = $input['new_email'];
                }
                if ($email !== null) {
                    $this->emailAvailable($email);
                    // Expired preparations never indefinitely reserve an address.
                    PlatformAccessRecovery::whereNotNull('reserved_email')->where('expires_at', '<=', now())->update(['reserved_email' => null]);
                }
                $snapshot = ['target_revision' => (int) $target->access_revision, 'target_name' => $target->name, 'target_email' => $target->email,
                    'target_active' => $target->active, 'target_roles' => $this->roles($target),
                    'institution_revision' => (int) $institution->operational_revision, 'institution_suspended' => ! $institution->status,
                    'agreement_revision' => (int) (InstitutionContract::where('institution_id', $institution->id)->lockForUpdate()->value('revision') ?? 0),
                    'successor_revision' => $successor ? (int) $successor->access_revision : null, 'successor_active' => $successor?->active,
                    'successor_email' => $successor?->email ?? $email, 'successor_roles' => $successor ? $this->roles($successor) : [],
                    'quota_before' => $this->policy->report($institution)];
                $plain = $kind === 'containment' ? null : Str::random(64);
                $operation = PlatformAccessRecovery::create(['id' => $id, 'operator_id' => $operator->id, 'institution_id' => $institution->id,
                    'target_id' => $target->id, 'kind' => $kind, 'state' => 'awaiting_verification', 'fingerprint' => $fingerprint,
                    'request' => $input, 'snapshot' => $snapshot, 'reserved_email' => $email,
                    'verification_hash' => $plain ? Hash::make($plain) : null, 'expires_at' => now()->addHour()]);
                if ($kind === 'containment') {
                    $before = $target->active;
                    $target->update(['active' => false]);
                    $this->access->revoke($target);
                    $report = $this->policy->report($institution);
                    $operation->update(['state' => 'completed', 'publication_status' => 'not_required', 'result' => [
                        'user_id' => $target->id, 'already_inactive' => ! $before, 'recovery_required' => in_array('NO_USABLE_ADMIN', $report['warnings'], true),
                        'institution_suspended' => ! $institution->status, 'access_revision' => (int) $target->access_revision, 'quotas' => $report]]);
                }
                $this->event($operator, $operation, $kind === 'containment' ? 'contained' : 'prepared');
                if ($plain) {
                    $publication = [$operation, $plain];
                }

                return $operation;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // A parallel preparation/finalization owns the ID or normalized address.
            $old = PlatformAccessRecovery::find($id);
            if ($old && $old->operator_id === $actor->id && hash_equals($old->fingerprint, $fingerprint)) {
                return $this->data($old);
            }
            throw new PlatformException($old ? 'IDEMPOTENCY_CONFLICT' : 'EMAIL_UNAVAILABLE', 409);
        }

        if ($publication) {
            $this->publishAfterCommit(...$publication);
        }

        return $this->data($operation->fresh());
    }

    private function emailAvailable(string $email): void
    {
        if (User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->lockForUpdate()->first(['id'])) {
            throw new PlatformException('EMAIL_UNAVAILABLE', 409);
        }
    }

    private function roles(User $user): array
    {
        return $user->roles()->pluck('type')->map(fn ($r) => $r->value)->unique()->sort()->values()->all();
    }

    private function event(PlatformOperator $actor, PlatformAccessRecovery $operation, string $action, ?string $reason = null): void
    {
        $this->audit->record($actor, 'access_recovery.'.$action, 'platform_access_recovery', $operation->id,
            [], ['kind' => $operation->kind, 'target_id' => $operation->target_id, 'state' => $operation->state,
                'authority' => $operation->request['authority'], 'snapshot' => $operation->snapshot, 'result' => $operation->result],
            $reason ?? $operation->request['reason'], $operation->institution_id, $operation->id);
    }

    private function publishAfterCommit(PlatformAccessRecovery $operation, string $plain): void
    {
        $publish = function () use ($operation, $plain) {
            try {
                Bus::dispatch(new SendInstitutionRecoveryProof($operation->id, $operation->generation, $plain));
                PlatformAccessRecovery::whereKey($operation->id)->where('generation', $operation->generation)->where('publication_status', 'pending')->update(['publication_status' => 'queued']);
            } catch (\Throwable) {
                try {
                    PlatformAccessRecovery::whereKey($operation->id)->where('generation', $operation->generation)->update(['publication_status' => 'failed']);
                } catch (\Throwable) { /* Durable preparation remains discoverable. */
                }
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($publish);
        } else {
            $publish();
        }
    }

    public function verify(string $id, string $plain, array $credentials): void
    {
        $candidate = PlatformAccessRecovery::findOrFail($id);
        DB::transaction(function () use ($candidate, $plain, $credentials) {
            Institution::whereKey($candidate->institution_id)->lockForUpdate()->firstOrFail();
            $operation = PlatformAccessRecovery::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($operation->state !== 'awaiting_verification' || $operation->expires_at->lte(now()) || ! $operation->verification_hash || ! Hash::check($plain, $operation->verification_hash)) {
                throw new PlatformException('RECOVERY_PROOF_INVALID', 400);
            }
            $newPerson = $operation->kind === 'replacement' && ! isset($operation->request['successor_id']);
            if ($newPerson) {
                Validator::make($credentials, ['password' => UserPassword::rules(), 'password_confirmation' => 'required|string'])->validate();
            } elseif ($credentials !== []) {
                throw new PlatformException('RECOVERY_EXISTING_CREDENTIALS_UNCHANGED', 422);
            }
            // Possession of this purpose-specific proof never issues a login token.
            $operation->update(['state' => 'verified', 'verified_at' => now(), 'verification_hash' => null,
                'prepared_password' => $newPerson ? Hash::make($credentials['password']) : null, 'revision' => $operation->revision + 1]);
            $this->audit->recordAddressProof($operation->id, $operation->institution_id);
        }, 3);
    }

    private function locked(PlatformOperator $actor, string $id): array
    {
        $operator = $this->actor($actor);
        $candidate = PlatformAccessRecovery::findOrFail($id);
        $institution = Institution::whereKey($candidate->institution_id)->lockForUpdate()->firstOrFail();
        $operation = PlatformAccessRecovery::whereKey($id)->lockForUpdate()->firstOrFail();

        return [$operator, $institution, $operation];
    }

    public function transition(PlatformOperator $actor, string $id, string $action, int $revision, ?string $reason = null): array
    {
        $publication = null;
        try {
            $operation = DB::transaction(function () use ($actor, $id, $action, $revision, $reason, &$publication) {
                $publication = null;
                [$operator, $institution, $operation] = $this->locked($actor, $id);
                if (($action === 'complete' && $operation->state === 'completed') || ($action === 'cancel' && $operation->state === 'cancelled')) {
                    return $operation;
                }
                if ($operation->revision !== $revision) {
                    throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
                }
                if (in_array($operation->state, ['completed', 'cancelled'], true)) {
                    throw new PlatformException('RECOVERY_STATE_CONFLICT', 409);
                }
                if ($action === 'cancel') {
                    $operation->update(['state' => 'cancelled', 'verification_hash' => null, 'prepared_password' => null, 'reserved_email' => null, 'revision' => $operation->revision + 1]);
                } else {
                    if ($operation->expires_at->lte(now())) {
                        throw new PlatformException('RECOVERY_EXPIRED', 409);
                    }
                    if ($action === 'resend') {
                        if ($operation->updated_at->gt(now()->subMinute())) {
                            throw new PlatformException('INVITATION_RESEND_THROTTLED', 429);
                        }
                        $plain = Str::random(64);
                        $operation->update(['state' => 'awaiting_verification', 'verification_hash' => Hash::make($plain), 'verified_at' => null,
                            'prepared_password' => null, 'generation' => $operation->generation + 1, 'publication_status' => 'pending', 'revision' => $operation->revision + 1]);
                        $publication = [$operation, $plain];
                    } else {
                        $this->complete($institution, $operation);
                    }
                }
                $this->event($operator, $operation, $action, $reason);

                return $operation;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new PlatformException('EMAIL_UNAVAILABLE', 409);
        }

        if ($publication) {
            $this->publishAfterCommit(...$publication);
        }

        return $this->data($operation->fresh());
    }

    private function complete(Institution $institution, PlatformAccessRecovery $operation): void
    {
        if ($operation->state !== 'verified' || ! $operation->verified_at) {
            throw new PlatformException('RECOVERY_VERIFICATION_REQUIRED', 409);
        }
        $target = User::where('institution_id', $institution->id)->whereKey($operation->target_id)->lockForUpdate()->firstOrFail();
        $snapshot = $operation->snapshot;
        $agreementRevision = (int) (InstitutionContract::where('institution_id', $institution->id)->lockForUpdate()->value('revision') ?? 0);
        if ((int) $target->access_revision !== $snapshot['target_revision'] || $target->email !== $snapshot['target_email'] || $target->active !== $snapshot['target_active']
            || $this->roles($target) !== $snapshot['target_roles'] || (int) $institution->operational_revision !== $snapshot['institution_revision'] || $agreementRevision !== $snapshot['agreement_revision']) {
            throw new PlatformException('RECOVERY_STALE', 409);
        }
        $request = $operation->request;
        if ($operation->kind === 'email_correction') {
            $this->emailAvailable($request['new_email']);
            if ($operation->reserved_email !== $request['new_email']) {
                throw new PlatformException('RECOVERY_STALE', 409);
            }
            // Delete old-email reset proofs before switching the address.
            $this->access->revoke($target);
            $target->forceFill(['email' => $request['new_email'], 'email_verified_at' => now()])->save();
            $result = ['user_id' => $target->id, 'email' => $target->email, 'active' => $target->active,
                'access_revision' => (int) $target->access_revision, 'recovery_eligible' => $target->active && $institution->status];
        } else {
            $successor = isset($request['successor_id']) ? User::where('institution_id', $institution->id)->whereKey($request['successor_id'])->lockForUpdate()->firstOrFail() : null;
            if ($successor && ((int) $successor->access_revision !== $snapshot['successor_revision'] || $successor->active !== $snapshot['successor_active']
                || $this->roles($successor) !== $snapshot['successor_roles'] || $successor->email !== $snapshot['successor_email'] || ! $successor->email_verified_at || ! $successor->password)) {
                throw new PlatformException('RECOVERY_STALE', 409);
            }
            $roles = ['admin'];
            $this->policy->checkReplacement($institution, $target, $successor, $roles);
            $adminRole = Rol::where('type', RoleType::Admin)->firstOrFail();
            if (! $successor) {
                $this->emailAvailable($request['successor_email']);
                if (! $operation->prepared_password || $operation->reserved_email !== $request['successor_email']) {
                    throw new PlatformException('RECOVERY_STALE', 409);
                }
                $successor = new User(['institution_id' => $institution->id, 'name' => $request['successor_name'], 'email' => $request['successor_email'], 'password' => $operation->prepared_password, 'active' => true]);
                $successor->forceFill(['email_verified_at' => $operation->verified_at])->save();
            }
            $successor->update(['active' => true]);
            $successor->roles()->sync([$adminRole->id]);
            $this->access->revoke($successor);
            $target->update(['active' => false]);
            $this->access->revoke($target);
            $result = ['user_id' => $target->id, 'successor_id' => $successor->id, 'successor_roles' => $roles,
                'outgoing_already_inactive' => ! $snapshot['target_active'], 'quotas' => $this->policy->report($institution)];
        }
        $result['institution_suspended'] = ! $institution->status;
        $operation->update(['state' => 'completed', 'result' => $result, 'prepared_password' => null, 'verification_hash' => null,
            'reserved_email' => null, 'revision' => $operation->revision + 1]);
    }
}
