<?php

namespace App\Platform\Http;

use App\Rules\UserPassword;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformInput
{
    public static function validate(Request $request, array $rules): array
    {
        if ($request->isMethod('GET')) {
            foreach (['available', 'status'] as $field) {
                if (array_key_exists($field, $rules) && in_array($request->input($field), ['true', 'false'], true)) {
                    $request->merge([$field => $request->input($field) === 'true']);
                }
            }
        }
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['request' => ['Unexpected properties.']]);
        }

        $data = $request->validate($rules);
        foreach (['expected_revision', 'expected_plan_revision', 'expected_user_revision'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        if (isset($data['limits'])) {
            foreach ($data['limits'] as $role => $value) {
                $data['limits'][$role] = $value === null ? null : (int) $value;
            }
        }

        return $data;
    }

    public static function agreement(): array
    {
        return ['expected_revision' => 'required|integer|min:0', 'plan_id' => 'required|uuid', 'expected_plan_revision' => 'required|integer|min:1', 'agreement_kind' => 'required|in:paid,free_trial', 'lifecycle' => 'required|in:agreed,terminated', 'price_clp' => 'required|string|regex:/^\d{1,18}$/D', 'currency' => 'required|in:CLP', 'tax_treatment' => 'required|in:inclusive,exclusive', 'periodicity' => 'required|in:monthly,annual,one_time_pilot', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'present|nullable|date_format:Y-m-d', 'trial' => 'present|nullable|array:type,starts_on,ends_on|required_array_keys:type,starts_on,ends_on', 'trial.type' => 'required_with:trial|in:trial,pilot', 'trial.starts_on' => 'required_with:trial|date_format:Y-m-d', 'trial.ends_on' => 'required_with:trial|date_format:Y-m-d', 'exceptions' => 'present|array|max:20', 'exceptions.*' => 'array:condition,base_value,agreed_value,reason', 'exceptions.*.condition' => 'required|in:limits.admin,limits.editor,limits.reader,price_clp,periodicity,starts_on,ends_on,tax_treatment', 'exceptions.*.base_value' => 'present', 'exceptions.*.agreed_value' => 'present', 'exceptions.*.reason' => 'required|string|max:1000', 'reason' => 'required|string|max:1000'] + self::limits();
    }

    public static function password(): array
    {
        return UserPassword::rules();
    }

    public static function limits(): array
    {
        return ['limits' => ['required', 'array:admin,editor,reader'], 'limits.admin' => ['present', 'nullable', 'integer', 'min:0', 'max:2147483647'], 'limits.editor' => ['present', 'nullable', 'integer', 'min:0', 'max:2147483647'], 'limits.reader' => ['present', 'nullable', 'integer', 'min:0', 'max:2147483647']];
    }

    public static function customer(): array
    {
        return ['customer' => ['present', 'nullable', 'array:type,name,rut'], 'customer.type' => ['nullable', 'in:natural_person,legal_entity'], 'customer.name' => ['nullable', 'string', 'max:255'], 'customer.rut' => ['nullable', 'string', 'max:32']];
    }
}
