<?php

namespace App\Authorization;

use App\Enums\InstitutionAbility;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InstitutionAbilityProjection
{
    /** @return list<string> */
    public function project(User $user): array
    {
        $user->loadMissing('roles:id,type');
        $gate = Gate::forUser($user);

        return collect(InstitutionAbility::cases())
            ->filter(fn (InstitutionAbility $ability): bool => $gate->allows($ability->value))
            ->map(fn (InstitutionAbility $ability): string => $ability->value)
            ->values()
            ->all();
    }
}
