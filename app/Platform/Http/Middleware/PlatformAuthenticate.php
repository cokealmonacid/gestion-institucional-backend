<?php

namespace App\Platform\Http\Middleware;

use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PlatformAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;
        if (! $token || ! ($operator = $token->tokenable) instanceof PlatformOperator || ! $token->expires_at || $token->expires_at->lte(now())) {
            throw new PlatformException('AUTH_UNAUTHENTICATED', 401);
        }
        if (! $operator->active || ! $operator->verified_at || ! $operator->mfa_confirmed_at || ! $operator->totp_secret || ! $token->can('platform') || $token->platform_mfa_verified_at === null || $token->platform_auth_epoch === null || (int) $token->platform_auth_epoch !== $operator->auth_epoch) {
            throw new PlatformException('PLATFORM_ACCESS_DENIED', 403);
        }
        $operator->withAccessToken($token);
        $request->setUserResolver(fn () => $operator);

        return $next($request);
    }
}
