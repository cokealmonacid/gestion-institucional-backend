<?php

namespace App\Services;

use App\Models\User;

class OnboardingStateResolver
{
    /** @return array{state: string, next_step: string} */
    public function resolve(User $user): array
    {
        // Institution provisioning/suspension belongs to the subsequent onboarding stages.
        $state = ! $user->hasVerifiedEmail()
            ? 'verify_email'
            : ($user->institution === null ? 'create_institution' : 'ready');

        return ['state' => $state, 'next_step' => $state];
    }

    public function hasInstitutionAccess(User $user): bool
    {
        if (! $user->active || $this->resolve($user)['state'] !== 'ready') {
            return false;
        }

        // A pending token must never acquire institutional access merely through a DB edit.
        $token = $user->currentAccessToken();

        return $token === null || ! $token->can('onboarding') || $token->can('*');
    }
}
