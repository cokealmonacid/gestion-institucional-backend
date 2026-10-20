<?php

namespace App\Platform\Services;

use App\Models\Rol;
use App\Models\User;
use App\Models\UserInvitation;
use App\Platform\Models\PlatformOperation;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use App\Services\UserAccess\InvitationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Institution\Services\MembershipPolicy;

class CustomerOnboarding
{
    public const ONBOARDING = 'institution_onboarding';

    public const ADMIN_INVITATION = 'administrator_invitation';

    public function __construct(private PlatformAuthentication $auth, private CommercialAdministration $commercial, private AdministrativeEventRecorder $audit, private InvitationService $invitations, private InvitationPublication $publication, private MembershipPolicy $policy) {}

    public function normalize(array $data, bool $onboarding): array
    {
        $person = $onboarding ? 'initial_admin' : null;
        if ($person) {
            $data[$person]['name'] = trim($data[$person]['name']);
            $data[$person]['email'] = Str::lower(trim($data[$person]['email']));
            $data['institution']['name'] = trim($data['institution']['name']);
            $customer = $this->commercial->customer($data['customer']);
            $data['customer'] = ['type' => $customer['customer_type'], 'name' => $customer['customer_name'], 'rut' => $customer['customer_rut']];
            $c = &$data['contract'];
            $c['plan_id'] = strtolower($c['plan_id']);
            $c['expected_plan_revision'] = (int) $c['expected_plan_revision'];
            foreach ($c['limits'] as &$limit) {
                $limit = $limit === null ? null : (int) $limit;
            } unset($limit);
            $c['price_clp'] = ltrim($c['price_clp'], '0') ?: '0';
            $c['reason'] = trim($c['reason']);
            foreach ($c['exceptions'] as &$exception) {
                $exception['reason'] = trim($exception['reason']);
            } unset($exception);
            usort($c['exceptions'], fn ($a, $b) => strcmp($a['condition'], $b['condition']));
            unset($c);
        } else {
            $data['name'] = trim($data['name']);
            $data['email'] = Str::lower(trim($data['email']));
            $data['reason'] = trim($data['reason']);
        }

        return $this->canonical($data);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                $child = $this->canonical($child);
            }
        } unset($child);

        return $value;
    }

    public function create(PlatformOperator $actor, string $key, array $data, ?string $institutionId = null): array
    {
        $key = strtolower($key);
        $institutionId = $institutionId === null ? null : strtolower($institutionId);
        $onboarding = $institutionId === null;
        $type = $onboarding ? self::ONBOARDING : self::ADMIN_INVITATION;
        $data = $this->normalize($data, $onboarding);
        $fingerprint = hash('sha256', json_encode($this->canonical(['institution_id' => $institutionId, 'request' => $data]), JSON_THROW_ON_ERROR));
        $saved = DB::transaction(function () use ($actor, $key, $data, $institutionId, $onboarding, $type, $fingerprint) {
            // The existing operator lock serializes equal-owner keys before the unique insert.
            // Database uniqueness is the final authority; different operators have independent keys.
            $operator = $this->auth->authorized($actor);
            Gate::forUser($operator)->authorize($onboarding ? 'platform.metadata' : 'platform.administrator_access');
            if ($onboarding) {
                Gate::forUser($operator)->authorize('platform.contracts');
            }
            $existing = PlatformOperation::where('operator_id', $operator->id)->where('operation_type', $type)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $this->replay($existing, $fingerprint);
            }
            try {
                $operation = PlatformOperation::create(['operator_id' => $operator->id, 'operation_type' => $type, 'idempotency_key' => $key, 'fingerprint' => $fingerprint]);
            } catch (UniqueConstraintViolationException $exception) {
                $winner = PlatformOperation::where('operator_id', $operator->id)->where('operation_type', $type)->where('idempotency_key', $key)->lockForUpdate()->first();
                if (! $winner) {
                    throw $exception;
                }

                return $this->replay($winner, $fingerprint);
            }
            if ($onboarding) {
                $institution = Institution::create(['name' => $data['institution']['name'], 'status' => true]);
                $institution->forceFill($this->commercial->customer($data['customer']) + ['operational_revision' => 0, 'auth_epoch' => 0, 'commercial_state' => 'legacy_unconfigured'])->save();
                $revision = $this->commercial->revise($actor, $institution->id, $data['contract'] + ['expected_revision' => 0], $operation->id);
                $person = $data['initial_admin'];
                $reason = $data['contract']['reason'];
            } else {
                $institution = Institution::whereKey($institutionId)->lockForUpdate()->firstOrFail();
                app(InstitutionalAccess::class)->enabled($institution);
                $person = $data;
                $reason = $data['reason'];
            }
            $role = Rol::where('type', 'admin')->first();
            if (! $role) {
                throw new PlatformException('ADMINISTRATOR_ROLE_NOT_CONFIGURED', 500);
            }
            $this->policy->check($institution, null, ['admin'], true);
            if (User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?', [$person['email']])->exists()) {
                throw new PlatformException('EMAIL_UNAVAILABLE', 409);
            }
            try {
                $user = User::create(['institution_id' => $institution->id, 'name' => $person['name'], 'email' => $person['email'], 'active' => true, 'password' => null]);
            } catch (UniqueConstraintViolationException) {
                throw new PlatformException('EMAIL_UNAVAILABLE', 409);
            }
            $user->roles()->attach($role->id);
            [$invitation, $plain] = $this->invitations->issueLocked($user);
            $base = '/api/v1/platform/institutions/'.$institution->id.'/administrators/'.$user->id.'/invitation';
            $result = ['operation_id' => $operation->id, 'institution_id' => $institution->id,
                $onboarding ? 'initial_admin_id' : 'administrator_id' => $user->id,
                'invitation_id' => $invitation->id, 'invitation_generation' => $invitation->generation,
                'activation_status' => 'pending_activation', 'delivery_status' => 'pending', 'publication_status' => 'unknown', 'status_url' => $base, 'resend_url' => $base];
            if ($onboarding) {
                $result['contract_revision'] = $revision->revision;
            }
            $this->audit->record($operator, $onboarding ? 'institution.onboarded' : 'administrator.invited', 'institution', $institution->id, [],
                ['roles' => ['admin'], 'administrator_id' => $user->id, 'invitation_id' => $invitation->id, 'generation' => $invitation->generation, 'active' => true], $reason, $institution->id, $operation->id);
            $operation->update(['result' => $result, 'committed_at' => now()]);

            return ['result' => $result, 'replayed' => false, 'operation' => $operation, 'invitation' => $invitation, 'plain' => $plain];
        }, 3);
        if ($saved['replayed']) {
            return $saved;
        }
        $response = ['result' => $saved['result'], 'replayed' => false];
        $publish = function () use ($saved, $actor, &$response): void {
            // Every exception here is post-commit: retain identifiers and an honest unknown outcome.
            try {
                $result = array_replace($saved['result'], $this->publication->publish($saved['invitation'], $saved['plain']));
                DB::transaction(function () use ($saved, $actor, $result) {
                    $saved['operation']->update(['result' => $result]);
                    $this->audit->record($actor, 'invitation.publication_recorded', 'user_invitation', $saved['invitation']->id, [],
                        ['generation' => $saved['invitation']->generation, 'publication_status' => $result['publication_status'], 'delivery_status' => $result['delivery_status']], 'Post-commit publication outcome', $result['institution_id'], $saved['operation']->id);
                });
                $response['result'] = $result;
            } catch (\Throwable) {
                try {
                    Log::warning('Committed platform operation publication state unavailable.', ['operation_id' => $saved['operation']->id]);
                } catch (\Throwable) {
                }
            }
        };
        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($publish);
            } else {
                $publish();
            }
        } catch (\Throwable) {
            try {
                Log::warning('Committed platform operation publication deferred.', ['operation_id' => $saved['operation']->id]);
            } catch (\Throwable) {
            }
        }

        return $response;
    }

    private function replay(PlatformOperation $operation, string $fingerprint): array
    {
        if (! hash_equals($operation->fingerprint, $fingerprint)) {
            throw new PlatformException('IDEMPOTENCY_CONFLICT', 409);
        }
        if (! $operation->committed_at || ! is_array($operation->result)) {
            throw new PlatformException('OPERATION_RESULT_UNAVAILABLE', 409);
        }

        return ['result' => $operation->result, 'replayed' => true];
    }

    public function lookup(PlatformOperator $actor, string $key, string $type): array
    {
        return DB::transaction(function () use ($actor, $key, $type) {
            $operator = $this->auth->authorized($actor);
            Gate::forUser($operator)->authorize($type === self::ONBOARDING ? 'platform.metadata' : 'platform.administrator_access');
            if ($type === self::ONBOARDING) {
                Gate::forUser($operator)->authorize('platform.contracts');
            }
            $operation = PlatformOperation::where('operator_id', $operator->id)->where('operation_type', $type)->where('idempotency_key', $key)->whereNotNull('committed_at')->firstOrFail();
            $result = $operation->result;
            $invitation = UserInvitation::find($result['invitation_id']);
            if ($invitation && $invitation->generation === $result['invitation_generation']) {
                $result['delivery_status'] = $invitation->delivery_status;
                if ($this->publication->stale($invitation)) {
                    $result['publication_status'] = 'unknown';
                }
            }

            return ['operation_id' => $operation->id, 'operation_type' => $operation->operation_type, 'committed_at' => $operation->committed_at->toISOString(), 'result' => $result];
        });
    }
}
