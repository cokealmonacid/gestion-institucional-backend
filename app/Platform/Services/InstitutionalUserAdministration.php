<?php

namespace App\Platform\Services;

use App\Enums\RoleType;
use App\Models\User;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Institution\Services\InstitutionalRoleAssignment;
use Modules\Institution\Services\MembershipPolicy;
use Modules\Institution\Services\RoleChangeImpact;

class InstitutionalUserAdministration
{
    public function __construct(private PlatformAuthentication $auth, private InstitutionalAccess $access, private MembershipPolicy $policy, private AdministrativeEventRecorder $audit) {}

    public function execute(PlatformOperator $actor, string $institutionId, string $userId, string $action, array $data): array
    {
        return DB::transaction(function () use ($actor, $institutionId, $userId, $action, $data) {
            $operator = $this->auth->authorized($actor);
            Gate::forUser($operator)->authorize('platform.user_access');
            $institution = Institution::whereKey($institutionId)->lockForUpdate()->firstOrFail();
            $this->access->enabled($institution);
            $user = User::where('institution_id', $institutionId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($action === 'impact') {
                return app(RoleChangeImpact::class)->inspect($user, RoleType::from($data['role'])) + ['access_revision' => (int) $user->access_revision];
            }
            if ((int) $user->access_revision !== $data['expected_user_revision']) {
                throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
            }
            $roles = $user->roles()->pluck('type')->map(fn ($role) => $role->value)->sort()->values()->all();
            $before = ['roles' => $roles, 'active' => $user->active, 'access_revision' => (int) $user->access_revision];
            $afterRoles = $action === 'role' ? [$data['role']] : $roles;
            $active = $action === 'access' ? (bool) $data['active'] : $user->active;
            $this->policy->check($institution, $user, $afterRoles, $active);
            if ($action === 'role') {
                app(InstitutionalRoleAssignment::class)->replace($user, RoleType::from($data['role']), $operator, $data);
            }
            $user->update(['active' => $active]);
            if ($roles !== $afterRoles || $before['active'] !== $active) {
                $this->access->revoke($user);
                $this->audit->record($operator, $action === 'role' ? 'user.role_changed' : 'user.activity_changed', 'institution_user', $user->id,
                    $before, ['roles' => $afterRoles, 'active' => $active, 'access_revision' => (int) $user->access_revision], $data['reason'], $institutionId);
            }

            return ['user_id' => $user->id, 'institution_id' => $institutionId, 'roles' => $afterRoles, 'active' => $active, 'access_revision' => (int) $user->access_revision];
        }, 3);
    }
}
