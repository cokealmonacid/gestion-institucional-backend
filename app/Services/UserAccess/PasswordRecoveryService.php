<?php

namespace App\Services\UserAccess;

use App\Jobs\SendPasswordRecovery;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
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
            DB::table('password_reset_tokens')->where('email', $credentials['email'])->lockForUpdate()->first();

            if (! $institution?->status || ! $user?->active || $user->email_verified_at === null) {
                return false;
            }

            $status = Password::broker()->reset($credentials, function (User $resetUser, string $password): void {
                $resetUser->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                $resetUser->tokens()->delete();
                event(new PasswordReset($resetUser));
            });

            return $status === Password::PASSWORD_RESET;
        }, 3);
    }
}
