<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Services\InstitutionalAccess;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        // Web guest/public routes and logout must retain their normal behavior.
        if (! $request->is('api/*') && ($request->is('logout') || ! $request->route()?->gatherMiddleware()
            || ! collect($request->route()->gatherMiddleware())->contains(fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:')))) {
            return $next($request);
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($request->is('api/v1/auth/logout')) {
            return $next($request);
        }
        if ($actor->institution_id === null) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! $actor->active) {
            return response()->json(['success' => false, 'message' => 'This account is inactive.'], 403);
        }
        $check = function () use ($request, $actor) {
            $institution = app(InstitutionalAccess::class)->lock($actor);
            if (! $actor->currentAccessToken() instanceof PersonalAccessToken && $request->hasSession()
                && ($request->session()->get('institution_auth_epoch') !== (int) $institution->auth_epoch
                    || $request->session()->get('user_access_revision') !== (int) User::whereKey($actor->id)->value('access_revision'))) {
                throw new InstitutionAccessException('AUTH_UNAUTHENTICATED', 401);
            }
        };
        // DB-only compatibility mutations are serialized here before their own resource locks.
        // Membership/mail and upload actions own their boundary so publication/staging stays outside locks.
        $ownsBoundary = $request->is('api/v1/institution/users', 'api/v1/institution/user-invitations', 'api/v1/institution/users/*/invitation')
            || ($request->isMethod('POST') && preg_match('#^api/v1/documents/[^/]+/versions$#', $request->path()));
        if (! $request->isMethodSafe() && ! $ownsBoundary) {
            return DB::transaction(function () use ($check, $next, $request) {
                $check();
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }

                return $response;
            }); // No retries around a controller which may have non-DB side effects.
        }
        DB::transaction($check);
        $response = $next($request);
        if ($request->isMethodSafe()) {
            DB::transaction($check); // Recheck before response/stream emission, never lock the stream.
        }

        return $response;
    }
}
