<?php

namespace Tests\Feature\Platform;

use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\CatalogPlan;
use App\Platform\Models\InstitutionContractRevision;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\ValueObjects\ChileanRut;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Models\Institution;

class CommercialAdministrationTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withToken($this->token($this->operator()));
    }

    private function planPayload(): array
    {
        return ['code' => 'basic', 'name' => 'Basic', 'description' => 'Initial plan', 'available_for_new_contracts' => true, 'limits' => ['admin' => 2, 'editor' => 5, 'reader' => null]];
    }

    private function plan(): array
    {
        return $this->postJson('/api/v1/platform/plans', $this->planPayload())->assertCreated()->json('data');
    }

    public function test_boolean_query_filters_follow_the_openapi_representation(): void
    {
        $this->plan();
        $this->getJson('/api/v1/platform/plans?available=true')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/platform/plans?available=false')->assertOk()->assertJsonCount(0, 'data.items');
        Institution::factory()->create(['status' => false]);
        $this->getJson('/api/v1/platform/institutions?status=false')->assertOk()->assertJsonCount(1, 'data.items');
    }

    private function agreement(array $plan, int $revision = 0): array
    {
        return ['expected_revision' => $revision, 'plan_id' => $plan['id'], 'expected_plan_revision' => $plan['revision'], 'agreement_kind' => 'free_trial', 'lifecycle' => 'agreed', 'limits' => $plan['limits'], 'price_clp' => '0', 'currency' => 'CLP', 'tax_treatment' => 'inclusive', 'periodicity' => 'monthly', 'starts_on' => '2026-10-01', 'ends_on' => null, 'trial' => ['type' => 'trial', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'], 'exceptions' => [], 'reason' => 'Trial conditions'];
    }

    public function test_legacy_institutions_and_roles_are_preserved_without_invented_contracts(): void
    {
        $institution = Institution::factory()->create();
        $this->getJson('/api/v1/platform/institutions/'.$institution->id)->assertOk()->assertJsonPath('data.commercial_state', 'legacy_unconfigured')->assertJsonPath('data.quota_enforcement', 'enforced');
        $this->getJson('/api/v1/platform/institutions/'.$institution->id.'/contract')->assertOk()->assertJsonPath('data.contract', null);
        $this->postJson('/api/v1/platform/institutions', [])->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_customer_rut_normalization_natural_person_and_repetition_across_spaces(): void
    {
        $this->assertSame('12345678-5', ChileanRut::normalize(' 12.345.678-5 '));
        foreach (range(1, 2) as $index) {
            $i = Institution::factory()->create();
            $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 0, 'name' => 'Space '.$index, 'customer' => ['type' => 'natural_person', 'name' => 'Synthetic Person', 'rut' => '12.345.678-5'], 'reason' => 'Identity metadata'])->assertOk()->assertJsonPath('data.customer.rut', '12345678-5');
        }
        $this->assertSame(2, DB::table('institutions')->where('customer_rut', '12345678-5')->count());
        $i = Institution::first();
        $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 1, 'name' => 'Space', 'customer' => ['type' => 'legal_entity', 'name' => 'Synthetic Entity', 'rut' => '12345678-9'], 'reason' => 'Invalid fixture'])->assertUnprocessable();
    }

    public function test_immutable_revisions_optimistic_conflicts_and_catalog_changes_do_not_modify_agreements(): void
    {
        $plan = $this->plan();
        $i = Institution::factory()->create();
        $path = '/api/v1/platform/institutions/'.$i->id.'/contract/revisions';
        $agreement = $this->agreement($plan);
        $first = $this->postJson($path, $agreement)->assertCreated()->assertJsonPath('data.quota_enforcement', 'enforced')->json('data');
        $this->patchJson('/api/v1/platform/plans/'.$plan['id'], array_diff_key($this->planPayload(), ['code' => true]) + ['expected_revision' => 1, 'reason' => 'Catalog revision'])->assertOk();
        $edit = array_diff_key($this->planPayload(), ['code' => true]);
        $edit['limits']['editor'] = 0;
        $edit['available_for_new_contracts'] = false;
        $this->patchJson('/api/v1/platform/plans/'.$plan['id'], $edit + ['expected_revision' => 2, 'reason' => 'Withdraw catalog'])->assertOk();
        $this->getJson('/api/v1/platform/institutions/'.$i->id.'/contract')->assertOk()->assertJsonPath('data.contract.conditions.source_plan.revision', 1)->assertJsonPath('data.contract.conditions.limits.editor', 5);
        $this->postJson($path, $agreement)->assertConflict();
        $agreement['expected_revision'] = 1;
        $agreement['limits']['editor'] = 7;
        $this->postJson($path, $agreement)->assertUnprocessable();
        $agreement['exceptions'] = [['condition' => 'limits.editor', 'base_value' => 5, 'agreed_value' => 7, 'reason' => 'Negotiated seats']];
        $this->postJson($path, $agreement)->assertCreated()->assertJsonPath('data.revision', 2);
        $this->assertSame($first['conditions'], InstitutionContractRevision::find($first['id'])->conditions);
        $this->expectException(\LogicException::class);
        InstitutionContractRevision::find($first['id'])->update(['conditions' => []]);
    }

    public function test_paid_identity_dates_currency_and_strict_allowlists(): void
    {
        $plan = $this->plan();
        $i = Institution::factory()->create();
        $d = $this->agreement($plan);
        $d['agreement_kind'] = 'paid';
        $d['price_clp'] = '10000';
        $d['trial'] = null;
        $path = '/api/v1/platform/institutions/'.$i->id.'/contract/revisions';
        $this->postJson($path, $d)->assertUnprocessable();
        $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 0, 'name' => 'Paid space', 'customer' => ['type' => 'natural_person', 'name' => 'Person fixture', 'rut' => '12345678-5'], 'reason' => 'Paid identity'])->assertOk();
        $this->postJson($path, $d + ['payment_provider' => 'none'])->assertUnprocessable();
        $invalid = $d;
        $invalid['ends_on'] = '2026-09-30';
        $this->postJson($path, $invalid)->assertUnprocessable();
        $invalid = $d;
        $invalid['currency'] = 'USD';
        $this->postJson($path, $invalid)->assertUnprocessable();
        $this->postJson($path, $d)->assertCreated()->assertJsonPath('data.conditions.customer.name', 'Person fixture');
        $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 1, 'name' => 'Paid space', 'customer' => null, 'reason' => 'Cannot erase identity'])->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_plan_metadata_and_contract(): void
    {
        $plan = $this->plan();
        $i = Institution::factory()->create();
        $before = AdministrativeEvent::count();
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson('/api/v1/platform/institutions/'.$i->id.'/contract/revisions', $this->agreement($plan))->assertStatus(500);
        $this->assertDatabaseCount('institution_contracts', 0);
        $this->assertDatabaseCount('institution_contract_revisions', 0);
        $this->assertSame('legacy_unconfigured', $i->fresh()->commercial_state);
        $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 0, 'name' => 'Changed', 'customer' => null, 'reason' => 'Edit'])->assertStatus(500);
        $this->assertSame($i->name, $i->fresh()->name);
        $this->patchJson('/api/v1/platform/plans/'.$plan['id'], array_diff_key($this->planPayload(), ['code' => true]) + ['expected_revision' => 1, 'reason' => 'Edit'])->assertStatus(500);
        $this->assertSame(1, CatalogPlan::find($plan['id'])->revision);
        $this->assertSame($before, AdministrativeEvent::count());
    }

    public function test_stale_metadata_and_scoped_history_cursor(): void
    {
        $plan = $this->plan();
        $i = Institution::factory()->create();
        $other = Institution::factory()->create();
        $this->postJson('/api/v1/platform/institutions/'.$i->id.'/contract/revisions', $this->agreement($plan))->assertCreated();
        $revision = InstitutionContractRevision::first();
        $this->getJson('/api/v1/platform/institutions/'.$other->id.'/contract/revisions?cursor='.$revision->id)->assertUnprocessable();
        $this->getJson('/api/v1/platform/institutions/'.$i->id.'/audit')->assertOk()->assertJsonCount(1, 'data.items');
        $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 9, 'name' => 'Stale', 'customer' => null, 'reason' => 'Edit'])->assertConflict();
        $this->getJson('/api/v1/platform/plans?per_page=101')->assertUnprocessable();
    }

    public function test_public_legacy_listing_does_not_leak_new_customer_metadata(): void
    {
        $i = Institution::factory()->create();
        $this->patchJson('/api/v1/platform/institutions/'.$i->id, ['expected_revision' => 0, 'name' => 'Existing label', 'customer' => ['type' => 'natural_person', 'name' => 'Private customer fixture', 'rut' => '12345678-5'], 'reason' => 'Metadata'])->assertOk();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/institutions')->assertOk()->assertJsonMissing(['customer_name' => 'Private customer fixture'])->assertJsonMissing(['customer_rut' => '12345678-5']);
    }
}
