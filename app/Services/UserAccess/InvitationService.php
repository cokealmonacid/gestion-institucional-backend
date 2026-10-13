<?php

namespace App\Services\UserAccess;

use App\Jobs\SendUserInvitation;
use App\Models\User;
use App\Models\UserInvitation;
use App\Rules\UserPassword;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use RuntimeException;
use Throwable;

class InvitationService
{
    /** @return array{0: UserInvitation, 1: string} */
    public function issueLocked(User $user): array
    {
        $token = Str::random(64);
        $invitation = UserInvitation::query()->where('user_id', $user->id)->lockForUpdate()->first();
        $generation = ($invitation?->generation ?? 0) + 1;

        $values = [
            'token_hash' => Hash::make($token),
            'generation' => $generation,
            'delivery_status' => 'pending',
            'expires_at' => now()->addMinutes(config('user-access.invitation.expire_minutes', 2880)),
            'last_sent_at' => now(),
            'delivery_attempted_at' => null,
            'provider_accepted_at' => null,
            'failed_at' => null,
            'consumed_at' => null,
        ];

        if ($invitation) {
            $invitation->update($values);
        } else {
            $invitation = UserInvitation::create(['user_id' => $user->id] + $values);
        }

        return [$invitation, $token];
    }

    public function publish(UserInvitation $invitation, string $token): bool
    {
        try {
            Bus::dispatch((new SendUserInvitation($invitation->id, $invitation->generation, $token))->afterCommit());

            return true;
        } catch (Throwable) {
            UserInvitation::query()->whereKey($invitation->id)->where('generation', $invitation->generation)->update([
                'delivery_status' => 'failed',
                'failed_at' => now(),
            ]);

            return false;
        }
    }

    /** @return array{0: UserInvitation, 1: string} */
    public function reissue(User $actor, string $userId): array
    {
        return DB::transaction(function () use ($actor, $userId): array {
            $institution = Institution::query()->whereKey($actor->institution_id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($userId)->where('institution_id', $institution->id)->lockForUpdate()->firstOrFail();

            if (! $institution->status || ! $user->active || $user->email_verified_at !== null) {
                throw new RuntimeException('INVITATION_NOT_AVAILABLE');
            }

            $existing = UserInvitation::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $minimum = (int) config('user-access.invitation.throttle_seconds', 60);
            if ($existing?->last_sent_at?->gt(now()->subSeconds($minimum))) {
                throw new RuntimeException('INVITATION_RESEND_THROTTLED');
            }

            return $this->issueLocked($user);
        }, 3);
    }

    public function activate(array $credentials): bool
    {
        return DB::transaction(function () use ($credentials): bool {
            $candidate = User::query()->where('email', $credentials['email'])->first();
            if (! $candidate) {
                return false;
            }

            $institution = Institution::query()->whereKey($candidate->institution_id)->lockForUpdate()->first();
            $user = User::query()->whereKey($candidate->id)->lockForUpdate()->first();
            $invitation = UserInvitation::query()->where('user_id', $candidate->id)->lockForUpdate()->first();

            if (! $institution?->status || ! $user?->active || $user->email_verified_at !== null
                || ! $invitation || $invitation->token_hash === null || $invitation->consumed_at !== null
                || $invitation->expires_at->isPast() || ! Hash::check($credentials['token'], $invitation->token_hash)) {
                return false;
            }

            $user->forceFill([
                'password' => $credentials['password'],
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $invitation->update([
                'token_hash' => null,
                'consumed_at' => now(),
            ]);

            return true;
        }, 3);
    }

    /** @return array<int, mixed> */
    public static function passwordRules(): array
    {
        return UserPassword::rules();
    }
}
