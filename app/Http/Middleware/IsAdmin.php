<?php

namespace App\Http\Middleware;

use App\Enums\RoleType;
use Closure;
use Illuminate\Http\Request;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $isAdmin = auth()->user()->roles()->where('type', RoleType::Admin)->exists();

        if (!$isAdmin) {
            return response()->json([
                'message' => 'Forbidden.'
            ], 403);
        }

        return $next($request);
    }
}
