<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\OnboardingStateResolver;
use Closure;
use Illuminate\Http\Request;

class EnsureInstitutionAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! app(OnboardingStateResolver::class)->hasInstitutionAccess($request->user())) {
            return ApiResponse::error('ONBOARDING_REQUIRED', 'Complete onboarding before accessing the institution.', 403);
        }

        return $next($request);
    }
}
