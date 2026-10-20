<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Http\PlatformInput;
use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\CatalogPlan;
use App\Platform\Models\InstitutionContract;
use App\Platform\Models\InstitutionContractRevision;
use App\Platform\Services\CommercialAdministration;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Institution\Models\Institution;

class AdministrationController extends Controller
{
    public function __construct(private CommercialAdministration $commercial) {}

    private function response(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => 'Platform administration request completed.'], $status);
    }

    private function page($query, int $size, callable $map): array
    {
        $page = $query->paginate($size);

        return ['items' => $page->getCollection()->map($map)->values()->all(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    public function plans(Request $r)
    {
        $d = PlatformInput::validate($r, ['q' => 'sometimes|string|max:100', 'available' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = CatalogPlan::query();
        if (isset($d['q'])) {
            $query->where('name', 'like', '%'.$d['q'].'%');
        } if (isset($d['available'])) {
            $query->where('available_for_new_contracts', $d['available']);
        }

        return $this->response($this->page($query->orderBy('name')->orderBy('id'), $d['per_page'] ?? 15, $this->commercial->planData(...)));
    }

    public function plan(Request $r, string $id)
    {
        PlatformInput::validate($r, []);

        return $this->response($this->commercial->planData(CatalogPlan::findOrFail($id)));
    }

    private function planInput(Request $r, bool $update): array
    {
        $rules = ['name' => 'required|string|max:255', 'description' => 'required|string|max:5000', 'available_for_new_contracts' => 'required|boolean'] + PlatformInput::limits();
        $rules += $update ? ['expected_revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000'] : ['code' => 'required|string|regex:/^[a-z0-9][a-z0-9_-]{0,63}$/D|unique:catalog_plans,code'];

        return PlatformInput::validate($r, $rules);
    }

    public function createPlan(Request $r)
    {
        return $this->response($this->commercial->planData($this->commercial->plan($r->user(), $this->planInput($r, false))), 201);
    }

    public function updatePlan(Request $r, string $id)
    {
        return $this->response($this->commercial->planData($this->commercial->plan($r->user(), $this->planInput($r, true), $id)));
    }

    public function institutions(Request $r)
    {
        $d = PlatformInput::validate($r, ['q' => 'sometimes|string|max:100', 'status' => 'sometimes|boolean', 'commercial_state' => 'sometimes|in:legacy_unconfigured,configured', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = Institution::query();
        if (isset($d['q'])) {
            $query->where('name', 'like', '%'.$d['q'].'%');
        } foreach (['status', 'commercial_state'] as $field) {
            if (isset($d[$field])) {
                $query->where($field, $d[$field]);
            }
        }

        return $this->response($this->page($query->orderBy('name')->orderBy('id'), $d['per_page'] ?? 15, $this->commercial->institutionData(...)));
    }

    public function institution(Request $r, string $id)
    {
        PlatformInput::validate($r, []);

        return $this->response($this->commercial->details($r->user(), $id));
    }

    public function metadata(Request $r, string $id)
    {
        $d = PlatformInput::validate($r, ['expected_revision' => 'required|integer|min:0', 'name' => 'required|string|max:255', 'reason' => 'required|string|max:1000'] + PlatformInput::customer());

        return $this->response($this->commercial->institutionData($this->commercial->metadata($r->user(), $id, $d)));
    }

    public function contract(Request $r, string $id)
    {
        PlatformInput::validate($r, []);
        Institution::findOrFail($id);
        $contract = InstitutionContract::where('institution_id', $id)->first();

        return $this->response(['contract' => $contract ? $this->commercial->revisionData(InstitutionContractRevision::findOrFail($contract->current_revision_id)) : null, 'commercial_state' => $contract ? 'configured' : 'legacy_unconfigured', 'quota_enforcement' => 'enforced']);
    }

    public function revise(Request $r, string $id)
    {
        $rules = PlatformInput::agreement();

        return $this->response($this->commercial->revisionData($this->commercial->revise($r->user(), $id, PlatformInput::validate($r, $rules))), 201);
    }

    private function history(Request $r, $query, callable $map, string $time = 'effective_at', array $filters = []): array
    {
        $d = PlatformInput::validate($r, ['cursor' => 'sometimes|uuid', 'limit' => 'sometimes|integer|min:1|max:100'] + $filters);
        foreach (array_keys($filters) as $field) {
            if (isset($d[$field])) {
                $query->where($field, $d[$field]);
            }
        }
        if (isset($d['cursor'])) {
            $anchor = (clone $query)->whereKey($d['cursor'])->first();
            if (! $anchor) {
                throw ValidationException::withMessages(['cursor' => ['Cursor is unavailable in this scope.']]);
            } $value = $anchor->getRawOriginal($time);
            $query->where(fn ($q) => $q->where($time, '<', $value)->orWhere(fn ($q) => $q->where($time, $value)->where('id', '<', $anchor->id)));
        }
        $limit = $d['limit'] ?? 20;
        $rows = $query->orderByDesc($time)->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = $rows->take($limit);

        return ['items' => $items->map($map)->values()->all(), 'next_cursor' => $more ? $items->last()->id : null];
    }

    public function revisions(Request $r, string $id)
    {
        Institution::findOrFail($id);

        return $this->response($this->history($r, InstitutionContractRevision::where('institution_id', $id), $this->commercial->revisionData(...)));
    }

    public function audit(Request $r, ?string $id = null)
    {
        if ($id) {
            Institution::findOrFail($id);
        } $query = AdministrativeEvent::query();
        if ($id) {
            $query->where('institution_id', $id);
        }

        return $this->response($this->history($r, $query, fn ($e) => $e->only(['id', 'actor_kind', 'actor_id', 'actor_name', 'action', 'subject_kind', 'subject_id', 'institution_id', 'before_values', 'after_values', 'reason', 'operation_id', 'occurred_at']), 'occurred_at', ['action' => 'sometimes|string|max:100', 'subject_kind' => 'sometimes|string|max:100', 'subject_id' => 'sometimes|uuid']));
    }
}
