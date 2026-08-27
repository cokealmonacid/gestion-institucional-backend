<?php

namespace App\Authorization;

use App\Enums\InstitutionAbility;
use App\Enums\RoleType;
use App\Models\User;

class InstitutionAuthorization
{
    /** @var array<string, list<RoleType>> */
    private const MANAGE_ROLES = [
        InstitutionAbility::ManageUsers->value => [RoleType::Admin],
        InstitutionAbility::ManageNodes->value => [RoleType::Admin],
        InstitutionAbility::ManageDocuments->value => [RoleType::Admin, RoleType::Editor],
        InstitutionAbility::ManageVersions->value => [RoleType::Admin, RoleType::Editor],
        InstitutionAbility::ManageTags->value => [RoleType::Admin, RoleType::Editor],
        InstitutionAbility::TagDocuments->value => [RoleType::Admin, RoleType::Editor],
        InstitutionAbility::ManageComments->value => [RoleType::Admin, RoleType::Editor],
    ];

    public function allows(User $actor, InstitutionAbility $ability): bool
    {
        if ($actor->institution_id === null) {
            return false;
        }

        if (in_array($ability, [InstitutionAbility::View, InstitutionAbility::ViewTraceability], true)) {
            return true;
        }

        $allowedRoles = array_map(
            fn (RoleType $role): string => $role->value,
            self::MANAGE_ROLES[$ability->value] ?? [],
        );

        return $allowedRoles !== []
            && $actor->roles()->whereIn('type', $allowedRoles)->exists();
    }
}
