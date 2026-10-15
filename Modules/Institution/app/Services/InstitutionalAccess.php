<?php

namespace Modules\Institution\Services;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Models\Institution;

class InstitutionalAccess
{
    // Every caller must be inside a transaction: institution before users/resources.
    public function lock(User $actor, ?string $ability = null): Institution
    {
        $institution = Institution::whereKey($actor->institution_id)->lockForUpdate()->firstOrFail();
        $this->enabled($institution);
        $current = User::whereKey($actor->id)->where('institution_id', $institution->id)->lockForUpdate()->first();
        if (! $current?->active) {
            throw new InstitutionAccessException('AUTH_ACCOUNT_INACTIVE', 403);
        }
        $credential = $actor->currentAccessToken();
        if ($credential instanceof PersonalAccessToken) {
            $stored = $current->tokens()->whereKey($credential->id)->lockForUpdate()->first();
            if (! $stored || $stored->institution_auth_epoch === null || (int) $stored->institution_auth_epoch !== (int) $institution->auth_epoch) {
                throw new InstitutionAccessException('AUTH_UNAUTHENTICATED', 401);
            }
        }
        if ($ability) {
            $roleIds = DB::table('role_user')->where('user_id', $current->id)->orderBy('role_id')->lockForUpdate()->pluck('role_id');
            $current->setRelation('roles', Rol::whereIn('id', $roleIds)->sharedLock()->get());
            Gate::forUser($current)->authorize($ability);
        }

        return $institution;
    }

    public function enabled(Institution $institution): void
    {
        if (! $institution->status) {
            throw new InstitutionAccessException('INSTITUTION_SUSPENDED', 403);
        }
    }

    public function revoke(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('user_invitations')->where('user_id', $user->id)->update([
            'token_hash' => null, 'generation' => DB::raw('generation + 1'), 'revoked_at' => now(),
        ]);
        $user->forceFill(['remember_token' => Str::random(60), 'access_revision' => (int) $user->access_revision + 1])->save();
    }
}
