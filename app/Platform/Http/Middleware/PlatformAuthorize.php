<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlatformAuthorize
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        Gate::forUser($request->user())->authorize($permission);

        return $next($request);
    }
}
