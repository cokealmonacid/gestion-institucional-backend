<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\OnboardingStateResolver;
use Closure;
use Illuminate\Http\Request;

class EnsureInstitutionReady
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('onboarding.institution_enforcement_enabled')) {
            return $next($request);
        }

        $resolver = app(OnboardingStateResolver::class);
        $state = $resolver->resolve($request->user())['state'];

        if ($state === 'verify_email') {
            return ApiResponse::error('EMAIL_VERIFICATION_REQUIRED', 'Verify your email before accessing the institution.', 403);
        }
        if ($state === 'preparing') {
            return ApiResponse::error('INSTITUTION_PREPARING', 'Institution onboarding is not complete.', 403);
        }
        if ($state === 'suspended') {
            return ApiResponse::error('INSTITUTION_SUSPENDED', 'This institution is suspended.', 403);
        }
        if ($state !== 'ready' || ! $resolver->hasFullInstitutionSession($request->user())) {
            return ApiResponse::error('ONBOARDING_REQUIRED', 'Complete onboarding before accessing the institution.', 403);
        }

        return $next($request);
    }
}
