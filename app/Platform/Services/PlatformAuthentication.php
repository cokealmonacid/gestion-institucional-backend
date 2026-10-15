<?php

namespace App\Platform\Services;

use App\Platform\Models\PlatformAccessLink;
use App\Platform\Models\PlatformAuthChallenge;
use App\Platform\Models\PlatformOperator;
use App\Platform\Models\PlatformRecoveryCode;
use App\Platform\PlatformException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformAuthentication
{
    public function __construct(private TotpVerifier $totp, private AdministrativeEventRecorder $audit) {}

    public function login(string $email, string $password): array
    {
        return DB::transaction(function () use ($email, $password) {
            $operator = PlatformOperator::where('email', strtolower($email))->lockForUpdate()->first();
            if (! $operator || ! is_string($operator->password) || ! Hash::check($password, $operator->password) || ! $operator->verified_at) {
                throw new PlatformException('AUTH_INVALID_CREDENTIALS', 401);
            }
            if (! $operator->active) {
                throw new PlatformException('PLATFORM_OPERATOR_INACTIVE', 403);
            }

            return $this->challenge($operator, $operator->mfa_confirmed_at ? 'login' : 'enrollment');
        }, 3);
    }

    public function challenge(PlatformOperator $operator, string $purpose): array
    {
        PlatformAuthChallenge::where('operator_id', $operator->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        if ($purpose !== 'login') {
            $operator->update(['pending_totp_secret' => null, 'enrollment_expires_at' => null]);
        }
        $token = Str::random(64);
        $challenge = PlatformAuthChallenge::create(['operator_id' => $operator->id, 'token_hash' => Hash::make($token), 'purpose' => $purpose, 'auth_epoch' => $operator->auth_epoch, 'expires_at' => now()->addMinutes(5)]);
        $this->audit->record($operator, 'auth.challenge_issued', 'platform_operator', $operator->id, [], ['purpose' => $purpose]);

        return ['challenge_id' => $challenge->id, 'challenge_token' => $token, 'next_step' => $purpose === 'login' ? 'mfa_challenge' : 'mfa_enrollment', 'expires_at' => $challenge->expires_at->toISOString()];
    }

    public function prove(array $input, string $operation): array
    {
        $candidate = PlatformAuthChallenge::find($input['challenge_id']);
        if (! $candidate) {
            throw new PlatformException('MFA_CHALLENGE_INVALID');
        }
        $result = DB::transaction(function () use ($input, $operation, $candidate) {
            $operator = PlatformOperator::whereKey($candidate->operator_id)->lockForUpdate()->first();
            $challenge = PlatformAuthChallenge::whereKey($candidate->id)->lockForUpdate()->first();
            if ($operator?->enrollment_expires_at?->lte(now())) {
                $operator->update(['pending_totp_secret' => null, 'enrollment_expires_at' => null]);
            }
            if (! $operator?->active || ! $operator->verified_at || ! $challenge || $challenge->consumed_at || $challenge->expires_at->lte(now()) || $challenge->auth_epoch !== $operator->auth_epoch || $challenge->attempts >= 5) {
                return ['error' => 'MFA_CHALLENGE_INVALID'];
            }
            // Invalid proof attempt accounting commits, rather than being rolled back by an exception.
            $invalid = function () use ($challenge): array {
                $challenge->increment('attempts');

                return ['error' => 'MFA_CHALLENGE_INVALID'];
            };
            if (! Hash::check($input['challenge_token'], $challenge->token_hash)) {
                return $invalid();
            }
            $enrolling = in_array($challenge->purpose, ['enrollment', 'reenrollment'], true);
            if ($operation === 'enrollment') {
                if (! $enrolling || $operator->mfa_confirmed_at) {
                    return $invalid();
                }
                if (! $operator->pending_totp_secret || ! $operator->enrollment_expires_at?->gt(now())) {
                    $operator->update(['pending_totp_secret' => $this->totp->generate(), 'enrollment_expires_at' => $challenge->expires_at]);
                    $this->audit->record($operator, 'auth.enrollment_started', 'platform_operator', $operator->id, ['pending_enrollment' => false], ['pending_enrollment' => true]);
                }

                return ['provisioning_uri' => $this->totp->uri($operator->pending_totp_secret, $operator->email), 'manual_secret' => $operator->pending_totp_secret, 'expires_at' => $challenge->expires_at->toISOString()];
            }
            if ($operation === 'confirmation') {
                if (! $enrolling || $operator->mfa_confirmed_at || ! $operator->pending_totp_secret || ! $operator->enrollment_expires_at?->gt(now())) {
                    return $invalid();
                }
                $step = $this->totp->step($operator->pending_totp_secret, $input['code']);
                if ($step === null) {
                    return $invalid();
                }
                $operator->update(['totp_secret' => $operator->pending_totp_secret, 'pending_totp_secret' => null, 'enrollment_expires_at' => null, 'mfa_confirmed_at' => now(), 'last_totp_step' => $step]);
                $challenge->update(['consumed_at' => now()]);
                $codes = $this->replaceCodes($operator);
                $this->audit->record($operator, 'auth.mfa_enrolled', 'platform_operator', $operator->id, ['mfa_confirmed' => false], ['mfa_confirmed' => true]);

                return ['recovery_codes' => $codes, 'next_step' => 'login'];
            }
            if ($challenge->purpose !== 'login' || ! $operator->mfa_confirmed_at || ! $operator->totp_secret) {
                return $invalid();
            }
            if (isset($input['recovery_code'])) {
                $matched = null;
                foreach (PlatformRecoveryCode::where('operator_id', $operator->id)->whereNull('consumed_at')->orderBy('id')->lockForUpdate()->get() as $code) {
                    if (Hash::check($input['recovery_code'], $code->code_hash)) {
                        $matched = $code;
                        break;
                    }
                }
                if (! $matched) {
                    return $invalid();
                }
                $matched->update(['consumed_at' => now()]);
                $previousEpoch = $operator->auth_epoch;
                $this->revoke($operator);
                $operator->update(['totp_secret' => null, 'mfa_confirmed_at' => null, 'last_totp_step' => -1]);
                PlatformRecoveryCode::where('operator_id', $operator->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                $this->audit->record($operator, 'auth.recovery_used', 'platform_operator', $operator->id, ['mfa_confirmed' => true, 'auth_epoch' => $previousEpoch], ['mfa_confirmed' => false, 'auth_epoch' => $operator->auth_epoch]);

                return $this->challenge($operator, 'reenrollment');
            }
            $step = $this->totp->step($operator->totp_secret, $input['code']);
            if ($step === null || $step <= $operator->last_totp_step) {
                return $invalid();
            }
            $operator->update(['last_totp_step' => $step]);
            $challenge->update(['consumed_at' => now()]);
            $expires = now()->addMinutes(60);
            $issued = $operator->createToken('platform', ['platform'], $expires);
            $issued->accessToken->forceFill(['platform_auth_epoch' => $operator->auth_epoch, 'platform_mfa_verified_at' => now()])->save();
            $this->audit->record($operator, 'auth.login', 'platform_operator', $operator->id, [], ['session_issued' => true, 'auth_epoch' => $operator->auth_epoch]);

            return ['operator' => $operator->summary(), 'token' => $issued->plainTextToken, 'expires_at' => $expires->toISOString()];
        }, 3);
        if (isset($result['error'])) {
            throw new PlatformException($result['error']);
        }

        return $result;
    }

    private function replaceCodes(PlatformOperator $operator): array
    {
        PlatformRecoveryCode::where('operator_id', $operator->id)->delete();
        $plain = [];
        for ($i = 0; $i < 10; $i++) {
            $plain[] = $code = Str::random(32);
            PlatformRecoveryCode::create(['operator_id' => $operator->id, 'code_hash' => Hash::make($code)]);
        }

        return $plain;
    }

    public function revoke(PlatformOperator $operator): void
    {
        $operator->increment('auth_epoch');
        $operator->tokens()->delete();
        PlatformAuthChallenge::where('operator_id', $operator->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        PlatformAccessLink::where('operator_id', $operator->id)->update(['token_hash' => null]);
        $operator->update(['pending_totp_secret' => null, 'enrollment_expires_at' => null]);
    }

    public function authorized(PlatformOperator $actor): PlatformOperator
    {
        $operator = PlatformOperator::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $token = $actor->currentAccessToken();
        if (! $operator->active || ! $operator->verified_at || ! $operator->mfa_confirmed_at || ! $operator->totp_secret || ! $token || ! $token->can('platform') || ! $operator->tokens()->whereKey($token->id)->lockForUpdate()->first() || $token->platform_auth_epoch === null || (int) $token->platform_auth_epoch !== $operator->auth_epoch || ! $token->expires_at?->gt(now()) || ! $token->platform_mfa_verified_at) {
            throw new PlatformException('PLATFORM_ACCESS_DENIED', 403);
        }

        return $operator;
    }

    public function changePassword(PlatformOperator $actor, array $input): void
    {
        DB::transaction(function () use ($actor, $input) {
            $operator = $this->authorized($actor);
            if (! Hash::check($input['current_password'], $operator->password)) {
                throw new PlatformException('CURRENT_PASSWORD_INVALID', 422);
            }
            $operator->update(['password' => $input['password']]);
            $previousEpoch = $operator->auth_epoch;
            $this->revoke($operator);
            $this->audit->record($operator, 'auth.password_changed', 'platform_operator', $operator->id, ['auth_epoch' => $previousEpoch], ['auth_epoch' => $operator->auth_epoch]);
        }, 3);
    }

    public function regenerate(PlatformOperator $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input) {
            $operator = $this->authorized($actor);
            $step = $this->totp->step($operator->totp_secret, $input['code']);
            if (! Hash::check($input['current_password'], $operator->password) || $step === null || $step <= $operator->last_totp_step) {
                throw new PlatformException('MFA_CHALLENGE_INVALID');
            }
            $operator->update(['last_totp_step' => $step]);
            $codes = $this->replaceCodes($operator);
            $this->audit->record($operator, 'auth.codes_regenerated', 'platform_operator', $operator->id, [], ['count' => 10]);

            return ['recovery_codes' => $codes];
        }, 3);
    }
}
