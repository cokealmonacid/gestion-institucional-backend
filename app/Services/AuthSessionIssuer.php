<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthSessionIssuer
{
    public function issue(User $user, Request $request): array
    {
        $user->load(['institution:id,name,status,provisioning_status', 'roles:id,type']);
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

    public function exchange(User $user, Request $request, mixed $currentToken): array
    {
        return DB::transaction(function () use ($user, $request, $currentToken): array {
            $session = $this->issue($user, $request);
            $currentToken?->delete();

            return $session;
        });
    }
}
