<?php

namespace App\Authorization;

use App\Enums\InstitutionAbility;
use App\Enums\RoleType;
use App\Models\User;
use App\Services\OnboardingStateResolver;

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
        if (! config('onboarding.institution_enforcement_enabled')) {
            return $actor->institution_id !== null;
        }

        if (! app(OnboardingStateResolver::class)->hasInstitutionAccess($actor)) {
            return false;
        }

        if (in_array($ability, [InstitutionAbility::View, InstitutionAbility::ViewTraceability], true)) {
            return true;
        }

        $allowedRoles = array_map(
            fn (RoleType $role): string => $role->value,
            self::MANAGE_ROLES[$ability->value] ?? [],
        );

        if ($allowedRoles === []) {
            return false;
        }

        if ($actor->relationLoaded('roles')) {
            return $actor->roles->contains(
                fn (mixed $role): bool => $role->type instanceof RoleType
                    && in_array($role->type->value, $allowedRoles, true),
            );
        }

        return $actor->roles()->whereIn('type', $allowedRoles)->exists();
    }
}
