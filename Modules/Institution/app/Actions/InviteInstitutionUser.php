<?php

namespace Modules\Institution\Actions;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserAccess\InvitationService;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Models\Institution;

class InviteInstitutionUser
{
    /** @return array{0: User, 1: UserInvitation, 2: string} */
    public function execute(User $actor, array $attributes, InvitationService $invitations): array
    {
        return DB::transaction(function () use ($actor, $attributes, $invitations): array {
            $institution = Institution::query()->whereKey($actor->institution_id)->lockForUpdate()->firstOrFail();
            if (! $institution->status) {
                throw new \RuntimeException('INSTITUTION_INACTIVE');
            }

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

            return [$user, $invitation, $token];
        }, 3);
    }
}
