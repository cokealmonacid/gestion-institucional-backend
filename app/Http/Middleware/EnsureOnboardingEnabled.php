<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class EnsureOnboardingEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('onboarding.registration_enabled')) {
            return ApiResponse::error('ONBOARDING_DISABLED', 'Account onboarding is not available.', 503);
        }

        return $next($request);
    }
}
