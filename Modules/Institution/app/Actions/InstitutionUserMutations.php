<?php

namespace Modules\Institution\Actions;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Services\AdministrativeEventRecorder;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Exceptions\RoleChangeException;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Institution\Services\MembershipPolicy;
use Modules\Institution\Services\RoleChangeImpact;

class InstitutionUserMutations
{
    public function __construct(private InstitutionalAccess $access, private MembershipPolicy $policy, private AdministrativeEventRecorder $audit) {}

    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $institution = $this->access->lock($actor, 'users.manage');
            $role = Rol::where('type', $data['rol'])->firstOrFail();
            $this->policy->check($institution, null, [$data['rol']], true);
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'institution_id' => $institution->id, 'active' => true]);
            $user->roles()->attach($role->id);
            $this->audit->record($actor, 'user.created', 'institution_user', $user->id, [], ['active' => true, 'roles' => [$data['rol']]], 'Legacy institutional creation', $institution->id);

            return $user;
        }, 3);
    }

    public function update(User $actor, string $email, array $data): User
    {
        return DB::transaction(function () use ($actor, $email, $data) {
            $institution = $this->access->lock($actor, 'users.manage');
            $target = User::where('institution_id', $institution->id)->where('email', $email)->lockForUpdate()->firstOrFail();
            $beforeRoles = $target->roles()->pluck('type')->map(fn ($r) => $r->value)->all();
            // Preserve all existing memberships unless the published explicit role replacement is requested.
            $roles = isset($data['role']) ? [$data['role']] : $beforeRoles;
            $active = isset($data['active']) ? (bool) $data['active'] : $target->active;
            $this->policy->check($institution, $target, $roles, $active);
            $impact = app(RoleChangeImpact::class);
            $documents = collect();
            $requested = isset($data['role']) ? RoleType::from($data['role']) : null;
            if ($requested && $impact->losesEligibility($target, $requested)) {
                $documents = $impact->affectedDocuments($target, lock: true);
                if (! ($data['confirm_responsibility_removal'] ?? false) || ! isset($data['role_impact_token'])) {
                    throw new RoleChangeException('ROLE_CHANGE_IMPACT_CONFIRMATION_REQUIRED', 'Review and confirm the responsibility impact before changing this role.', 409);
                }
                if (! $impact->tokenMatches($target, $requested, $documents, $data['role_impact_token'])) {
                    throw new RoleChangeException('ROLE_CHANGE_IMPACT_STALE', 'The responsibility impact has changed. Review it again before confirming.', 409);
                }
            }
            $before = ['active' => $target->active, 'roles' => $beforeRoles, 'access_revision' => (int) $target->access_revision];
            $changed = $active !== $target->active || $roles !== $beforeRoles;
            $target->fill(array_intersect_key($data, array_flip(['name', 'active'])))->save();
            if ($requested) {
                $target->roles()->sync([Rol::where('type', $requested)->firstOrFail()->id]);
            }
            if ($changed) {
                $this->access->revoke($target);
                $this->audit->record($actor, 'user.access_updated', 'institution_user', $target->id, $before, ['active' => $active, 'roles' => $roles, 'access_revision' => (int) $target->access_revision], 'Institutional user access update', $institution->id);
            }
            foreach ($documents as $document) {
                app(DocumentResponsibilityWriter::class)->write($document, null, $actor, app(DocumentEventRecorder::class), DocumentResponsibilityWriter::ROLE_CHANGE_REASON);
            }

            return $target;
        }, 3);
    }

    public function delete(User $actor, string $email): void
    {
        DB::transaction(function () use ($actor, $email) {
            $institution = $this->access->lock($actor, 'users.manage');
            $target = User::where('institution_id', $institution->id)->where('email', $email)->lockForUpdate()->firstOrFail();
            $roles = $target->roles()->pluck('type')->map(fn ($r) => $r->value)->all();
            $this->policy->check($institution, $target, $roles, $target->active, true);
            $this->access->revoke($target);
            $target->delete();
            $this->audit->record($actor, 'user.deleted', 'institution_user', $target->id, ['active' => $target->active, 'roles' => $roles], ['deleted' => true], 'Institutional user deletion', $institution->id);
        }, 3);
    }
}
