<?php

namespace Modules\Institution\Services;

use App\Enums\RoleType;
use App\Models\User;
use Modules\Institution\Exceptions\InstitutionOwnerTransferRequiredException;
use Modules\Institution\Models\Institution;

class InstitutionAdministrationGuard
{
    public function ensureOwnerCanBeChanged(
        Institution $institution,
        User $user,
        ?RoleType $requestedRole = null,
        ?bool $requestedActive = null,
        bool $deleting = false,
    ): void {
        if ((string) $institution->owner_user_id !== (string) $user->id) {
            return;
        }

        if ($deleting || $requestedActive === false || ($requestedRole !== null && $requestedRole !== RoleType::Admin)) {
            throw new InstitutionOwnerTransferRequiredException;
        }
    }
}
