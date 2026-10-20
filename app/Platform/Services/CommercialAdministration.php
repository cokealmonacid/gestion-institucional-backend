<?php

namespace App\Platform\Services;

use App\Platform\Models\CatalogPlan;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\InstitutionContractRevision;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use App\Platform\ValueObjects\ChileanRut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\MembershipPolicy;

class CommercialAdministration
{
    public function __construct(private PlatformAuthentication $auth, private AdministrativeEventRecorder $audit) {}

    public function plan(PlatformOperator $actor, array $data, ?string $id = null): CatalogPlan
    {
        return DB::transaction(function () use ($actor, $data, $id) {
            $operator = $this->auth->authorized($actor);
            $plan = $id ? CatalogPlan::whereKey($id)->lockForUpdate()->firstOrFail() : new CatalogPlan;
            if ($id && $plan->revision !== $data['expected_revision']) {
                throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
            }
            $before = $plan->exists ? $this->planData($plan) : [];
            $values = array_intersect_key($data, array_flip(['code', 'name', 'description', 'available_for_new_contracts', 'limits']));
            if ($id) {
                unset($values['code']);
            }
            $plan->fill($values);
            $plan->revision = $id ? $plan->revision + 1 : 1;
            $plan->save();
            $this->audit->record($operator, $id ? 'plan.updated' : 'plan.created', 'catalog_plan', $plan->id, $before, $this->planData($plan), $data['reason'] ?? 'Catalog plan created');

            return $plan;
        }, 3);
    }

    public function planData(CatalogPlan $plan): array
    {
        return $plan->only(['id', 'code', 'name', 'description', 'available_for_new_contracts', 'limits', 'revision']);
    }

    public function customer(?array $data, bool $required = false): array
    {
        $values = ['customer_type' => $data['type'] ?? null, 'customer_name' => isset($data['name']) ? trim($data['name']) : null, 'customer_rut' => isset($data['rut']) ? ChileanRut::normalize($data['rut']) : null];
        if ($required && (! $values['customer_type'] || ! $values['customer_name'] || ! $values['customer_rut'])) {
            throw ValidationException::withMessages(['customer' => ['A paid agreement requires type, customer name and valid RUT.']]);
        }

        return $values;
    }

    public function metadata(PlatformOperator $actor, string $id, array $data): Institution
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $operator = $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $institution->operational_revision !== $data['expected_revision']) {
                throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
            }
            $contract = InstitutionContract::where('institution_id', $id)->lockForUpdate()->first();
            $revision = $contract ? InstitutionContractRevision::whereKey($contract->current_revision_id)->lockForUpdate()->first() : null;
            $values = $this->customer($data['customer'], ($revision?->conditions['agreement_kind'] ?? null) === 'paid');
            $before = $this->institutionData($institution);
            $institution->forceFill($values + ['name' => trim($data['name']), 'operational_revision' => (int) $institution->operational_revision + 1])->save();
            $this->audit->record($operator, 'institution.metadata_updated', 'institution', $id, $before, $this->institutionData($institution), $data['reason'], $id);

            return $institution;
        }, 3);
    }

    public function institutionData(Institution $institution): array
    {
        return ['id' => $institution->id, 'name' => $institution->name, 'status' => (bool) $institution->status, 'operational_revision' => (int) $institution->operational_revision, 'commercial_state' => $institution->commercial_state, 'customer' => ['type' => $institution->customer_type, 'name' => $institution->customer_name, 'rut' => $institution->customer_rut], 'quota_enforcement' => 'enforced'];
    }

    public function details(PlatformOperator $actor, string $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();
            $quotas = app(MembershipPolicy::class)->report($institution);
            $contract = InstitutionContract::where('institution_id', $id)->first();
            $revision = $contract ? InstitutionContractRevision::findOrFail($contract->current_revision_id) : null;
            if ($revision) {
                $revision->setRelation('quota_report', $quotas);
            }

            return $this->institutionData($institution) + ['current_contract' => $revision ? $this->revisionData($revision) : null, 'quotas' => $quotas, 'warnings' => $quotas['warnings']];
        });
    }

    public function revise(PlatformOperator $actor, string $id, array $data, ?string $operationId = null): InstitutionContractRevision
    {
        return DB::transaction(function () use ($actor, $id, $data, $operationId) {
            $operator = $this->auth->authorized($actor);
            $institution = Institution::whereKey($id)->lockForUpdate()->firstOrFail();
            $contract = InstitutionContract::where('institution_id', $id)->lockForUpdate()->first();
            if (($contract?->revision ?? 0) !== $data['expected_revision']) {
                throw new PlatformException('RESOURCE_REVISION_CONFLICT', 409);
            }
            $old = $contract ? InstitutionContractRevision::whereKey($contract->current_revision_id)->lockForUpdate()->firstOrFail() : null;
            if ($old && $old->conditions['source_plan']['id'] === $data['plan_id']) {
                $source = $old->conditions['source_plan'];
            } else {
                $plan = CatalogPlan::whereKey($data['plan_id'])->lockForUpdate()->firstOrFail();
                if (! $plan->available_for_new_contracts) {
                    throw new PlatformException('PLAN_NOT_AVAILABLE', 409);
                }
                $source = $this->planData($plan);
            }
            if ($source['revision'] !== $data['expected_plan_revision']) {
                throw new PlatformException('PLAN_REVISION_CONFLICT', 409);
            }
            $this->customer(['type' => $institution->customer_type, 'name' => $institution->customer_name, 'rut' => $institution->customer_rut], $data['agreement_kind'] === 'paid');
            $this->conditions($data, $source);
            $conditions = array_intersect_key($data, array_flip(['agreement_kind', 'lifecycle', 'limits', 'price_clp', 'currency', 'tax_treatment', 'periodicity', 'starts_on', 'ends_on', 'trial', 'exceptions', 'reason']));
            $conditions['source_plan'] = $source;
            $conditions['customer'] = $this->institutionData($institution)['customer'];
            if (! $contract) {
                $contract = InstitutionContract::create(['institution_id' => $id]);
            }
            $operation = $operationId ?? (string) Str::uuid();
            $revision = InstitutionContractRevision::create(['contract_id' => $contract->id, 'institution_id' => $id, 'revision' => $contract->revision + 1, 'effective_at' => now(), 'conditions' => $conditions, 'actor_id' => $operator->id, 'operation_id' => $operation]);
            $contract->update(['current_revision_id' => $revision->id, 'revision' => $revision->revision]);
            $institution->forceFill(['commercial_state' => 'configured'])->save();
            $this->audit->record($operator, 'contract.revised', 'institution_contract', $contract->id, $old?->conditions ?? [], $conditions, $data['reason'], $id, $operation);
            $revision->setRelation('quota_report', app(MembershipPolicy::class)->report($institution));

            return $revision;
        }, 3);
    }

    private function conditions(array &$data, array $source): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => [$message]]);
        $data['price_clp'] = ltrim($data['price_clp'], '0') ?: '0';
        if ($data['ends_on'] !== null && $data['ends_on'] < $data['starts_on']) {
            $fail('ends_on', 'End precedes start.');
        }
        $trial = $data['trial'];
        if ($trial && ($trial['ends_on'] < $trial['starts_on'] || $trial['starts_on'] < $data['starts_on'] || ($data['ends_on'] !== null && $trial['ends_on'] > $data['ends_on']))) {
            $fail('trial', 'Trial must fit contract dates.');
        }
        if ($data['agreement_kind'] === 'free_trial' && ($data['price_clp'] !== '0' || ! $trial)) {
            $fail('agreement_kind', 'Free trials require zero price and trial dates.');
        }
        if ($data['periodicity'] === 'one_time_pilot' && (! $trial || $trial['type'] !== 'pilot')) {
            $fail('trial', 'One-time pilot requires pilot dates.');
        }
        $exceptions = [];
        foreach ($data['exceptions'] as $exception) {
            $key = $exception['condition'];
            if (isset($exceptions[$key])) {
                $fail('exceptions', 'Duplicate condition.');
            }
            $exceptions[$key] = $exception;
            if (str_starts_with($key, 'limits.')) {
                $role = substr($key, 7);
                $base = $source['limits'][$role];
                $agreed = $data['limits'][$role];
            } else {
                $base = null;
                $agreed = $data[$key];
            }
            if ($exception['base_value'] !== $base || $exception['agreed_value'] !== $agreed) {
                $fail('exceptions', 'Exception contradicts conditions.');
            }
        }
        foreach (['admin', 'editor', 'reader'] as $role) {
            if ($data['limits'][$role] !== $source['limits'][$role] && ! isset($exceptions['limits.'.$role])) {
                $fail('exceptions', 'Changed role limit requires an exception.');
            }
        }
    }

    public function revisionData(InstitutionContractRevision $revision): array
    {
        $data = $revision->only(['id', 'contract_id', 'institution_id', 'revision', 'conditions']);
        $data['effective_at'] = $revision->effective_at->toISOString();
        $today = now('America/Santiago')->format('Y-m-d');
        $c = $revision->conditions;
        $data['validity'] = $today < $c['starts_on'] ? 'not_started' : (($c['ends_on'] !== null && $today > $c['ends_on']) ? 'expired' : 'current');
        $data['quota_enforcement'] = 'enforced';
        $data['payment_status'] = 'untracked';
        $data['quotas'] = $revision->relationLoaded('quota_report') ? $revision->getRelation('quota_report') : DB::transaction(function () use ($revision) {
            $institution = Institution::whereKey($revision->institution_id)->lockForUpdate()->firstOrFail();

            return app(MembershipPolicy::class)->report($institution);
        });

        return $data;
    }
}
