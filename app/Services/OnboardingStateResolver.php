<?php

namespace App\Services;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class OnboardingStateResolver
{
    /** @return array{state: string, next_step: string} */
    public function resolve(User $user): array
    {
        if (! $user->hasVerifiedEmail()) {
            $state = 'verify_email';
        } elseif ($user->institution_id === null) {
            $state = 'create_institution';
        } else {
            $institution = $user->relationLoaded('institution') ? $user->institution : $user->institution()->first();
            $state = ! $institution || ! $institution->status
                ? 'suspended'
                : ($institution->provisioning_status === 'ready' ? 'ready' : 'preparing');
        }

        return ['state' => $state, 'next_step' => $state];
    }

    public function hasFullInstitutionSession(User $user): bool
    {
        $token = $user->currentAccessToken();

        return ! $token instanceof PersonalAccessToken
            || ! is_array($token->abilities)
            || ! in_array('onboarding', $token->abilities, true);
    }

    public function hasInstitutionAccess(User $user): bool
    {
        return $user->active && $this->resolve($user)['state'] === 'ready' && $this->hasFullInstitutionSession($user);
    }
}
