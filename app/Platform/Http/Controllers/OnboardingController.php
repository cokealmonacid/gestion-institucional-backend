<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Http\PlatformInput;
use App\Platform\Services\CustomerOnboarding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OnboardingController extends Controller
{
    public function __construct(private CustomerOnboarding $onboarding) {}

    public function store(Request $r)
    {
        $rules = ['institution' => 'required|array:name', 'institution.name' => 'required|string|max:255',
            'initial_admin' => 'required|array:name,email', 'initial_admin.name' => 'required|string|max:255', 'initial_admin.email' => 'required|string|email|max:255'] + PlatformInput::customer();
        $agreement = PlatformInput::agreement();
        unset($agreement['expected_revision']);
        $root = array_filter(array_keys($agreement), fn ($key) => ! str_contains($key, '.'));
        $rules['contract'] = 'required|array:'.implode(',', $root);
        foreach ($agreement as $key => $rule) {
            if (is_string($rule)) {
                $rule = str_replace('required_with:trial', 'required_with:contract.trial', $rule);
            }
            $rules['contract.'.$key] = $rule;
        }
        $data = PlatformInput::validate($r, $rules);

        return $this->created($r, $data);
    }

    public function invite(Request $r, string $id)
    {
        $data = PlatformInput::validate($r, ['name' => 'required|string|max:255', 'email' => 'required|string|email|max:255', 'reason' => 'required|string|max:1000']);

        return $this->created($r, $data, $id);
    }

    private function created(Request $r, array $data, ?string $id = null)
    {
        $key = strtolower((string) $r->header('Idempotency-Key'));
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => 'required|uuid'])->validate();
        $result = $this->onboarding->create($r->user(), $key, $data, $id);

        return response()->json(['success' => true, 'data' => $result['result'], 'message' => 'Resources created. Publication state is reported separately.'], 201)
            ->header('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    public function operation(Request $r, string $key)
    {
        $data = PlatformInput::validate($r, ['operation_type' => 'required|in:institution_onboarding,administrator_invitation']);
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => 'required|uuid'])->validate();

        return response()->json(['success' => true, 'data' => $this->onboarding->lookup($r->user(), strtolower($key), $data['operation_type']), 'message' => 'Committed operation retrieved.']);
    }
}
