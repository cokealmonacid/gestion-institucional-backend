<?php

namespace Modules\Institution\Services;

use App\Models\User;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\InstitutionContractRevision;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Models\Institution;

class MembershipPolicy
{
    // Locking current reads avoid a stale repeatable-read snapshot and COUNT locks.
    public function state(Institution $institution): array
    {
        $users = User::where('institution_id', $institution->id)->orderBy('id')->lockForUpdate()->get();
        $rows = DB::table('role_user')->whereIn('user_id', $users->modelKeys())->orderBy('user_id')->orderBy('role_id')->lockForUpdate()->get();
        // Do not exclusively lock shared role definitions through a joined FOR UPDATE.
        $types = DB::table('roles')->whereIn('id', $rows->pluck('role_id')->unique())->sharedLock()->pluck('type', 'id');
        $pivots = $rows->map(fn ($row) => (object) ['user_id' => $row->user_id, 'type' => $types[$row->role_id]])->groupBy('user_id');
        $used = ['admin' => 0, 'editor' => 0, 'reader' => 0];
        $usable = [];
        $memberships = [];
        foreach ($users as $user) {
            $roles = ($pivots[$user->id] ?? collect())->pluck('type')->unique()->all();
            $memberships[$user->id] = $roles;
            if ($user->active) {
                foreach ($roles as $role) {
                    $used[$role]++;
                }
                if ($user->email_verified_at && is_string($user->password) && $user->password !== '' && in_array('admin', $roles, true)) {
                    $usable[] = $user->id;
                }
            }
        }
        $contract = InstitutionContract::where('institution_id', $institution->id)->lockForUpdate()->first();
        $revision = $contract ? InstitutionContractRevision::whereKey($contract->current_revision_id)->lockForUpdate()->firstOrFail() : null;

        return ['used' => $used, 'memberships' => $memberships, 'usable' => $usable,
            'limits' => $revision?->conditions['limits'] ?? ['admin' => null, 'editor' => null, 'reader' => null], 'agreement_revision' => $contract?->revision ?? 0];
    }

    public function check(Institution $institution, ?User $target, array $roles, bool $active, bool $deleted = false): void
    {
        $state = $this->state($institution);
        $before = $target?->active ? ($state['memberships'][$target->id] ?? []) : [];
        $after = $active && ! $deleted ? array_unique($roles) : [];
        foreach (['admin', 'editor', 'reader'] as $role) {
            $delta = (int) in_array($role, $after, true) - (int) in_array($role, $before, true);
            $limit = $state['limits'][$role];
            if ($delta > 0 && $limit !== null && $state['used'][$role] + $delta > $limit) {
                throw new InstitutionAccessException('USER_QUOTA_EXCEEDED', 409, ['role' => $role, 'limit' => $limit, 'used' => $state['used'][$role], 'requested_delta' => $delta]);
            }
        }
        if ($target && in_array($target->id, $state['usable'], true) && count($state['usable']) === 1
            && (! $active || $deleted || ! in_array('admin', $roles, true))) {
            throw new InstitutionAccessException('INSTITUTION_REQUIRES_USABLE_ADMIN');
        }
    }

    public function report(Institution $institution): array
    {
        $state = $this->state($institution);
        $roles = [];
        foreach ($state['used'] as $role => $used) {
            $limit = $state['limits'][$role];
            $roles[$role] = ['limit' => $limit, 'used' => $used, 'over_by' => $limit === null ? 0 : max(0, $used - $limit)];
        }

        return ['roles' => $roles, 'agreement_revision' => $state['agreement_revision'], 'computed_at' => now()->toISOString(),
            'warnings' => $state['usable'] === [] ? ['NO_USABLE_ADMIN'] : [], 'quota_enforcement' => 'enforced'];
    }
}
