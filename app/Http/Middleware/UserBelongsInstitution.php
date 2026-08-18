<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class UserBelongsInstitution
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (auth()->user()->institution_id != $request->institution_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
