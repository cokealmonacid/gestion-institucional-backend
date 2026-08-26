<?php

namespace App\Http\Middleware;

use App\Enums\InstitutionAbility;
use Closure;
use Illuminate\Http\Request;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $isAdmin = $request->user()?->can(InstitutionAbility::ManageUsers->value) ?? false;

        if (! $isAdmin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
