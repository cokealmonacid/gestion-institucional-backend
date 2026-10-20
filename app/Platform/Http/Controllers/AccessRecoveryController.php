<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Http\PlatformInput;
use App\Platform\Models\PlatformAccessRecovery;
use App\Platform\Services\InstitutionAccessRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccessRecoveryController extends Controller
{
    public function __construct(private InstitutionAccessRecovery $recovery) {}

    private function response(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => 'Recovery operation state retrieved.'], $status)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request)
    {
        $data = PlatformInput::validate($request, ['kind' => 'required|in:replacement,email_correction,containment',
            'institution_id' => 'required|uuid', 'target_id' => 'required|uuid',
            'expected_target_revision' => 'required|integer|min:0', 'expected_institution_revision' => 'required|integer|min:0',
            'successor_id' => 'sometimes|uuid', 'successor_name' => 'sometimes|required|string|max:255',
            'successor_email' => 'sometimes|required|email|max:255', 'new_email' => 'sometimes|required|email|max:255',
            'same_person' => 'sometimes|accepted', 'confirmation' => 'required|accepted', 'reason' => 'required|string|max:1000',
            'authority' => 'required|array:requester,relationship,channel,reference',
            'authority.requester' => 'required|string|max:255', 'authority.relationship' => 'required|string|max:255',
            'authority.channel' => 'required|string|max:255', 'authority.reference' => 'required|string|max:500']);
        $replacement = $data['kind'] === 'replacement';
        $email = $data['kind'] === 'email_correction';
        $existing = isset($data['successor_id']);
        if (($replacement && ($existing === isset($data['successor_email']) || (! $existing && ! isset($data['successor_name']))))
            || (! $replacement && array_intersect(['successor_id', 'successor_name', 'successor_email'], array_keys($data)) !== [])
            || ($existing && isset($data['successor_name'])) || ($email && (! isset($data['new_email']) || ! isset($data['same_person'])))
            || (! $email && (isset($data['new_email']) || isset($data['same_person'])))) {
            throw ValidationException::withMessages(['recovery' => ['Select exactly the inputs for this recovery purpose.']]);
        }
        $data['expected_target_revision'] = (int) $data['expected_target_revision'];
        $data['expected_institution_revision'] = (int) $data['expected_institution_revision'];
        $key = strtolower((string) $request->header('Idempotency-Key'));
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();

        return $this->response($this->recovery->prepare($request->user(), $key, $data), 201);
    }

    public function index(Request $request)
    {
        $data = PlatformInput::validate($request, ['institution_id' => 'sometimes|uuid', 'target_id' => 'sometimes|uuid', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = PlatformAccessRecovery::query();
        foreach (['institution_id', 'target_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 15);

        return $this->response(['items' => $page->getCollection()->map(fn ($operation) => $this->recovery->data($operation))->values()->all(),
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function show(Request $request, string $id)
    {
        PlatformInput::validate($request, []);

        return $this->response($this->recovery->data(PlatformAccessRecovery::findOrFail($id)));
    }

    public function transition(Request $request, string $id, string $action)
    {
        $data = PlatformInput::validate($request, ['expected_revision' => 'required|integer|min:1', 'confirmation' => 'required|accepted',
            'reason' => $action === 'complete' ? 'sometimes|required|string|max:1000' : 'required|string|max:1000']);

        return $this->response($this->recovery->transition($request->user(), $id, $action, $data['expected_revision'], $data['reason'] ?? null));
    }

    public function verify(Request $request)
    {
        $data = PlatformInput::validate($request, ['operation_id' => 'required|uuid', 'proof' => 'required|string|max:128',
            'password' => 'sometimes|required|string', 'password_confirmation' => 'sometimes|required|string']);
        $this->recovery->verify($data['operation_id'], $data['proof'], array_intersect_key($data, array_flip(['password', 'password_confirmation'])));

        return $this->response(['verified' => true, 'access_granted' => false]);
    }
}
