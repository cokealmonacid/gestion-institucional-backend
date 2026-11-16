<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            return ApiResponse::error('EMAIL_VERIFICATION_REQUIRED', 'Verify your email before creating an institution.', 403);
        }

        return $next($request);
    }
}
