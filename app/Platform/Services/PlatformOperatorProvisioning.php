<?php

namespace App\Platform\Services;

use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformOperatorProvisioning
{
    public function __construct(private PlatformAuthentication $auth, private PlatformAccessLinks $links, private AdministrativeEventRecorder $audit) {}

    public function execute(string $mode, array $input): array
    {
        if (! in_array($mode, ['bootstrap', 'provision', 'reissue-access', 'recover-mfa', 'deactivate'], true)) {
            throw new PlatformException('VALIDATION_ERROR', 422);
        }
        if ($mode === 'deactivate' && (($input['confirmation'] ?? false) !== true
            || ! Str::isUuid($input['operator_id'] ?? '') || ! Str::isUuid($input['operation_id'] ?? '')
            || trim($input['reason'] ?? '') === '' || trim($input['accountability'] ?? '') === '')) {
            throw new PlatformException('PLATFORM_DEACTIVATION_CONFIRMATION_REQUIRED', 422);
        }
        $result = DB::transaction(function () use ($mode, $input) {
            DB::table('platform_bootstrap_lock')->where('id', 1)->lockForUpdate()->first();
            $fingerprint = hash('sha256', json_encode([$mode, $input], JSON_THROW_ON_ERROR));
            $previous = DB::table('platform_cli_operations')->where('id', $input['operation_id'])->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new PlatformException('IDEMPOTENCY_CONFLICT', 409);
                }

                return ['operator_id' => $previous->operator_id, 'publication_status' => $mode === 'deactivate' ? 'not_applicable' : 'unchanged', 'replayed' => true];
            }
            if ($mode === 'deactivate') {
                // The shared CLI lock serializes lifecycle changes. Current reads of
                // all operators also serialize against login/MFA/password mutations.
                $operators = PlatformOperator::orderBy('id')->lockForUpdate()->get();
                $operator = $operators->firstWhere('id', $input['operator_id']);
                if (! $operator) {
                    throw (new ModelNotFoundException)->setModel(PlatformOperator::class);
                }
                $before = ['active' => $operator->active, 'auth_epoch' => $operator->auth_epoch];
                if ($operator->active) {
                    $remaining = $operators->contains(fn ($other) => $other->id !== $operator->id && $other->active
                        && $other->verified_at && is_string($other->password) && $other->password !== ''
                        && $other->mfa_confirmed_at && is_string($other->totp_secret) && $other->totp_secret !== '');
                    if (! $remaining) {
                        throw new PlatformException('PLATFORM_REQUIRES_USABLE_OPERATOR', 409);
                    }
                }
                $operator->update(['active' => false]);
                $this->auth->revoke($operator);
                DB::table('platform_recovery_codes')->where('operator_id', $operator->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                $this->audit->record($input['accountability'], 'operator.deactivate', 'platform_operator', $operator->id, $before,
                    ['active' => false, 'auth_epoch' => $operator->auth_epoch], $input['reason'], null, $input['operation_id']);
                DB::table('platform_cli_operations')->insert(['id' => $input['operation_id'], 'fingerprint' => $fingerprint, 'operator_id' => $operator->id, 'created_at' => now(), 'updated_at' => now()]);

                return ['operator_id' => $operator->id, 'publication_status' => 'not_applicable', 'replayed' => false];
            }
            if (in_array($mode, ['bootstrap', 'provision'], true)) {
                if ($mode === 'bootstrap' && PlatformOperator::exists()) {
                    throw new PlatformException('PLATFORM_ALREADY_BOOTSTRAPPED', 409);
                }
                if ($mode === 'provision' && ! PlatformOperator::exists()) {
                    throw new PlatformException('PLATFORM_BOOTSTRAP_REQUIRED', 409);
                }
                if (PlatformOperator::where('email', $input['email'])->exists()) {
                    throw new PlatformException('EMAIL_UNAVAILABLE', 409);
                }
                $operator = PlatformOperator::create(['name' => $input['name'], 'email' => $input['email']]);
            } else {
                $operator = PlatformOperator::whereKey($input['operator_id'])->lockForUpdate()->firstOrFail();
                if (! $operator->active) {
                    throw new PlatformException('PLATFORM_OPERATOR_INACTIVE', 403);
                }
                $this->auth->revoke($operator);
                if ($mode === 'recover-mfa') {
                    $operator->update(['totp_secret' => null, 'mfa_confirmed_at' => null, 'last_totp_step' => -1]);
                    DB::table('platform_recovery_codes')->where('operator_id', $operator->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                }
            }
            $issued = $this->links->issueLocked($operator, $operator->verified_at ? 'password_reset' : 'activation');
            $this->audit->record($input['accountability'], 'operator.'.$mode, 'platform_operator', $operator->id, [], ['active' => $operator->active, 'mfa_required' => true], $input['reason'], null, $input['operation_id']);
            DB::table('platform_cli_operations')->insert(['id' => $input['operation_id'], 'fingerprint' => $fingerprint, 'operator_id' => $operator->id, 'created_at' => now(), 'updated_at' => now()]);

            return ['operator_id' => $operator->id, 'issued' => $issued, 'replayed' => false];
        }, 3);
        if (isset($result['issued'])) {
            $issued = $result['issued'];
            unset($result['issued']);
            $result['publication_status'] = $this->links->publish(...$issued);
        }

        return $result;
    }
}
