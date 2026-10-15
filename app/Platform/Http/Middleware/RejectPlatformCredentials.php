<?php

namespace App\Platform\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Platform\Models\PlatformOperator;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class RejectPlatformCredentials
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/v1/platform/*') && $request->bearerToken() && PersonalAccessToken::findToken($request->bearerToken())?->tokenable instanceof PlatformOperator) {
            return ApiResponse::error('PLATFORM_ACCESS_DENIED', 'Platform credentials cannot access institutional routes.', 403);
        }

        return $next($request);
    }
}
