<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class AuthSessionIssuer
{
    public function issue(User $user, Request $request): array
    {
        $user->load(['institution:id,name', 'roles:id,type']);
        $onboarding = app(OnboardingStateResolver::class)->resolve($user);
        $pending = $onboarding['state'] !== 'ready';
        $token = $user->createToken(
            'Acervo-'.($pending ? 'Onboarding' : 'Auth'),
            $pending ? ['onboarding'] : ['*'],
            $pending ? now()->addHours(config('onboarding.token_hours')) : null,
        );
        $user->withAccessToken($token->accessToken);

        return [
            'user' => (new UserResource($user))->resolve($request),
            'token' => $token->plainTextToken,
            'onboarding' => $onboarding,
        ];
    }
}
