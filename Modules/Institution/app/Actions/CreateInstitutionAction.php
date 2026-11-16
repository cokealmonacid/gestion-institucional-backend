<?php

namespace Modules\Institution\Actions;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Exceptions\InstitutionCreationException;
use Modules\Institution\Models\Institution;

class CreateInstitutionAction
{
    /** @return array{institution: Institution, created: bool} */
    public function execute(User $actor, string $name): array
    {
        return DB::transaction(function () use ($actor, $name): array {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

            if ($user->institution_id !== null) {
                $institution = Institution::query()->findOrFail($user->institution_id);

                if ((string) $institution->owner_user_id === (string) $user->id) {
                    return ['institution' => $institution, 'created' => false];
                }

                throw new InstitutionCreationException(
                    'INSTITUTION_ALREADY_ASSIGNED',
                    'This account is already assigned to an institution.',
                    409,
                );
            }

            $adminRole = Rol::query()->where('type', RoleType::Admin)->lockForUpdate()->first();
            if ($adminRole === null) {
                throw new InstitutionCreationException(
                    'INSTITUTION_SETUP_UNAVAILABLE',
                    'Institution setup is temporarily unavailable.',
                    503,
                );
            }

            $institution = Institution::create(['name' => $name, 'status' => false]);
            $user->forceFill(['institution_id' => $institution->id])->save();
            $user->roles()->sync([$adminRole->id]);

            $institution->forceFill([
                'owner_user_id' => $user->id,
                'provisioning_status' => 'ready',
                'onboarding_completed_at' => now(),
                'status' => true,
            ])->save();

            return ['institution' => $institution, 'created' => true];
        });
    }
}
