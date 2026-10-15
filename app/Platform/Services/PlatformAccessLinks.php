<?php

namespace App\Platform\Services;

use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Models\PlatformAccessLink;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class PlatformAccessLinks
{
    public function __construct(private PlatformAuthentication $auth, private AdministrativeEventRecorder $audit) {}

    public function issueLocked(PlatformOperator $operator, string $purpose): array
    {
        $existing = PlatformAccessLink::where('operator_id', $operator->id)->where('purpose', $purpose)->lockForUpdate()->first();
        $token = Str::random(64);
        $values = ['token_hash' => Hash::make($token), 'generation' => ($existing?->generation ?? 0) + 1, 'expires_at' => now()->addMinutes(60), 'consumed_at' => null, 'publication_status' => 'pending'];
        if ($existing) {
            $existing->update($values);
        } else {
            $existing = PlatformAccessLink::create(['operator_id' => $operator->id, 'purpose' => $purpose] + $values);
        }

        return [$existing, $token];
    }

    public function publish(PlatformAccessLink $link, string $token): string
    {
        try {
            Bus::dispatch(new SendPlatformAccessLink($link->id, $link->generation, $token));

            return 'queued';
        } catch (Throwable) {
            try {
                PlatformAccessLink::whereKey($link->id)->where('generation', $link->generation)->update(['publication_status' => 'failed']);

                return 'failed';
            } catch (Throwable) {
                return 'unknown';
            }
        }
    }

    public function request(string $email): void
    {
        $issued = DB::transaction(function () use ($email) {
            $operator = PlatformOperator::where('email', strtolower($email))->lockForUpdate()->first();
            if (! $operator?->active || ! $operator->verified_at) {
                return null;
            }
            $existing = PlatformAccessLink::where('operator_id', $operator->id)->where('purpose', 'password_reset')->lockForUpdate()->first();
            if ($existing?->updated_at->gt(now()->subMinute())) {
                return null;
            }
            $issued = $this->issueLocked($operator, 'password_reset');
            $this->audit->record($operator, 'auth.recovery_requested', 'platform_operator', $operator->id);

            return $issued;
        }, 3);
        if ($issued) {
            $this->publish(...$issued);
        }
    }

    public function consume(string $purpose, array $input): void
    {
        DB::transaction(function () use ($purpose, $input) {
            $operator = PlatformOperator::where('email', strtolower($input['email']))->lockForUpdate()->first();
            $link = $operator ? PlatformAccessLink::where('operator_id', $operator->id)->where('purpose', $purpose)->lockForUpdate()->first() : null;
            if (! $operator?->active || ! $link?->token_hash || $link->consumed_at || $link->expires_at->lte(now()) || ! Hash::check($input['token'], $link->token_hash) || ($purpose === 'activation' && $operator->verified_at) || ($purpose === 'password_reset' && ! $operator->verified_at)) {
                throw new PlatformException('PLATFORM_LINK_INVALID');
            }
            $before = ['verified' => $operator->verified_at !== null, 'auth_epoch' => $operator->auth_epoch];
            $operator->update(['password' => $input['password'], 'verified_at' => $operator->verified_at ?? now()]);
            $this->auth->revoke($operator);
            $link->update(['token_hash' => null, 'consumed_at' => now()]);
            $this->audit->record($operator, $purpose === 'activation' ? 'auth.activated' : 'auth.password_reset', 'platform_operator', $operator->id, $before, ['verified' => true, 'auth_epoch' => $operator->auth_epoch]);
        }, 3);
    }
}
