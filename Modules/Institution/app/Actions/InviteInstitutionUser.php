<?php

namespace Modules\Institution\Actions;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Services\UserAccess\InvitationService;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Institution\Services\MembershipPolicy;

class InviteInstitutionUser
{
    /** @return array{0: User, 1: UserInvitation, 2: string} */
    public function execute(User $actor, array $attributes, InvitationService $invitations): array
    {
        return DB::transaction(function () use ($actor, $attributes, $invitations): array {
            $institution = app(InstitutionalAccess::class)->lock($actor, 'users.manage');
            app(MembershipPolicy::class)->check($institution, null, [$attributes['role']], true);

            $role = Rol::query()->where('type', RoleType::from($attributes['role']))->firstOrFail();
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => null,
                'institution_id' => $institution->id,
                'active' => true,
            ]);
            $user->roles()->attach($role->id);
            [$invitation, $token] = $invitations->issueLocked($user);

            app(AdministrativeEventRecorder::class)->record($actor, 'user.invited', 'institution_user', $user->id, [], ['active' => true, 'roles' => [$attributes['role']]], 'Institutional invitation', $institution->id);

            return [$user, $invitation, $token];
        }, 3);
    }
}
