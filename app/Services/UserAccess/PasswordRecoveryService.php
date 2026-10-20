<?php

namespace App\Services\UserAccess;

use App\Jobs\SendPasswordRecovery;
use App\Models\User;
use App\Platform\Services\AdministrativeEventRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Throwable;

class PasswordRecoveryService
{
    public function request(string $email): void
    {
        $issued = DB::transaction(function () use ($email): ?array {
            $candidate = User::query()->where('email', $email)->first();
            if (! $candidate) {
                return null;
            }

            $institution = Institution::query()->whereKey($candidate->institution_id)->lockForUpdate()->first();
            $user = User::query()->whereKey($candidate->id)->lockForUpdate()->first();
            DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();

            if (! $institution?->status || ! $user?->active || $user->email_verified_at === null) {
                return null;
            }

            return [$user, Password::broker()->createToken($user)];
        }, 3);

        if (! $issued) {
            return;
        }

        [$user, $token] = $issued;

        try {
            Bus::dispatch((new SendPasswordRecovery($user->id, $token))->afterCommit());
        } catch (Throwable) {
            DB::transaction(function () use ($user): void {
                $institution = Institution::query()->whereKey($user->institution_id)->lockForUpdate()->first();
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
                DB::table('password_reset_tokens')->where('email', $user->email)->lockForUpdate()->first();

                if ($institution && $locked) {
                    Password::broker()->deleteToken($locked);
                }
            }, 3);
        }
    }

    public function reset(array $credentials): bool
    {
        return DB::transaction(function () use ($credentials): bool {
            $candidate = User::query()->where('email', $credentials['email'])->first();
            if (! $candidate) {
                return false;
            }

            $institution = Institution::query()->whereKey($candidate->institution_id)->lockForUpdate()->first();
            $user = User::query()->whereKey($candidate->id)->lockForUpdate()->first();
            $proof = DB::table('password_reset_tokens')->where('email', $credentials['email'])->lockForUpdate()->first();

            if (! $institution?->status || ! $user?->active || $user->email_verified_at === null) {
                return false;
            }

            // The broker's ordinary SELECT can see an earlier REPEATABLE READ snapshot,
            // even after the locking read above observes consumption or a replacement.
            // Validate only the locked current proof and mutate the locked current user.
            if (! $proof || Carbon::parse($proof->created_at)->addMinutes((int) config('auth.passwords.users.expire', 60))->isPast()
                || ! Hash::check($credentials['token'], $proof->token)) {
                return false;
            }
            $user->forceFill(['password' => $credentials['password'], 'remember_token' => Str::random(60)])->save();
            app(InstitutionalAccess::class)->revoke($user);
            app(AdministrativeEventRecorder::class)->record($user, 'user.credentials_reset', 'institution_user', $user->id, [], ['access_revision' => (int) $user->access_revision], 'Institutional credential reset', $user->institution_id);
            event(new PasswordReset($user));

            return true;
        }, 3);
    }
}
