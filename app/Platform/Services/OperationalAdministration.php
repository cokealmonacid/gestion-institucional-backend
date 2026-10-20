<?php

namespace App\Platform\Services;

use App\Models\User;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use App\Services\UserAccess\InvitationService;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Institution\Services\MembershipPolicy;

class OperationalAdministration
{
    public function __construct(private PlatformAuthentication $auth, private AdministrativeEventRecorder $audit, private InstitutionalAccess $access, private MembershipPolicy $policy) {}

    public function status(PlatformOperator $actor, string $id, bool $enabled, array $data): Institution
    {
        return DB::transaction(function () use ($actor, $id, $enabled, $data) {
            $operator = $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $institution->operational_revision !== $data['expected_revision']) {
                throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
            }
            if ($institution->status === $enabled) {
                return $institution;
            }
            $before = ['status' => $institution->status, 'operational_revision' => (int) $institution->operational_revision, 'auth_epoch' => (int) $institution->auth_epoch];
            $institution->forceFill(['status' => $enabled, 'operational_revision' => (int) $institution->operational_revision + 1, 'auth_epoch' => (int) $institution->auth_epoch + 1])->save();
            // Even deleted users' remember credentials and persisted sessions are invalidated.
            foreach (User::withTrashed()->where('institution_id', $id)->orderBy('id')->lockForUpdate()->get() as $user) {
                $this->access->revoke($user);
            }
            $this->audit->record($operator, $enabled ? 'institution.reactivated' : 'institution.suspended', 'institution', $id, $before,
                ['status' => $enabled, 'operational_revision' => (int) $institution->operational_revision, 'auth_epoch' => (int) $institution->auth_epoch], $data['reason'], $id);

            return $institution;
        }, 3);
    }

    private function administrator(Institution $institution, string $userId): User
    {
        // Foreign, deleted and non-admin accounts share the same unavailable result.
        return User::where('institution_id', $institution->id)->whereHas('roles', fn ($q) => $q->where('type', 'admin'))
            ->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    public function administratorStatus(PlatformOperator $actor, string $id, string $userId): array
    {
        return DB::transaction(function () use ($actor, $id, $userId) {
            $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();

            return $this->administratorData($this->administrator($institution, $userId));
        });
    }

    public function administratorData(User $user): array
    {
        $invitation = $user->invitation()->first();
        $unknown = app(InvitationPublication::class)->stale($invitation);

        return ['user_id' => $user->id, 'active' => $user->active, 'access_revision' => (int) $user->access_revision,
            'activation_status' => $user->email_verified_at ? 'activated' : 'pending_activation',
            'usable_administrator' => $user->active && $user->email_verified_at !== null && is_string($user->password) && $user->password !== '',
            'invitation_id' => $invitation?->id, 'generation' => $invitation?->generation, 'delivery_status' => $invitation?->delivery_status,
            'publication_status' => $unknown ? 'unknown' : ($invitation?->delivery_status === 'failed' ? 'failed' : ($invitation ? 'queued' : null)),
            'expires_at' => $invitation?->expires_at?->toISOString(), 'revoked_at' => $invitation?->revoked_at?->toISOString()];
    }

    public function reactivateAdministrator(PlatformOperator $actor, string $id, string $userId, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $userId, $data) {
            $operator = $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->enabled($institution);
            $user = $this->administrator($institution, $userId);
            if ((int) $user->access_revision !== $data['expected_user_revision']) {
                throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
            }
            if ($user->active) {
                return $this->administratorData($user);
            }
            $roles = $user->roles()->pluck('type')->map(fn ($r) => $r->value)->all();
            $this->policy->check($institution, $user, $roles, true);
            $before = ['active' => false, 'access_revision' => (int) $user->access_revision];
            $user->update(['active' => true]);
            $this->access->revoke($user);
            $this->audit->record($operator, 'administrator.reactivated', 'institution_user', $user->id, $before,
                ['active' => true, 'access_revision' => (int) $user->access_revision], $data['reason'], $id);

            return $this->administratorData($user);
        }, 3);
    }

    public function resendAdministrator(PlatformOperator $actor, string $id, string $userId, array $data): array
    {
        [$user, $invitation, $token] = DB::transaction(function () use ($actor, $id, $userId, $data) {
            $operator = $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->enabled($institution);
            $user = $this->administrator($institution, $userId);
            if (! $user->active || $user->email_verified_at !== null) {
                throw new PlatformException('INVITATION_NOT_AVAILABLE', 409);
            }
            $existing = $user->invitation()->lockForUpdate()->first();
            if ($existing?->last_sent_at?->gt(now()->subSeconds((int) config('user-access.invitation.throttle_seconds', 60)))) {
                throw new PlatformException('INVITATION_RESEND_THROTTLED', 429);
            }
            // Resend consumes the already reserved seat; it remains allowed over quota.
            [$invitation, $token] = app(InvitationService::class)->issueLocked($user);
            $this->audit->record($operator, 'administrator.invitation_reissued', 'institution_user', $user->id, [],
                ['generation' => $invitation->generation, 'access_revision' => (int) $user->access_revision], $data['reason'], $id);

            return [$user, $invitation, $token];
        }, 3);
        // No lock or transaction around queue publication/transport. A committed repair stays discoverable.
        try {
            $published = app(InvitationService::class)->publish($invitation, $token);
            $result = $this->administratorData($user);
            $result['publication_status'] = $published ? 'queued' : 'failed';

            return $result;
        } catch (\Throwable) {
            return ['user_id' => $user->id, 'access_revision' => (int) $user->access_revision, 'publication_status' => 'unknown'];
        }
    }

    public function quotas(PlatformOperator $actor, string $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            $this->auth->authorized($actor);

            return $this->policy->report(Institution::whereKey($id)->lockForUpdate()->firstOrFail());
        });
    }
}
