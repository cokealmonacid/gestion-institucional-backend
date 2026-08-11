<?php

namespace Modules\Institution\Http\Requests;

use App\Enums\RoleType;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Nodes\Support\NodeName;

class CreateNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->whereIn('type', [RoleType::Admin->value, RoleType::Editor->value])
            ->exists() ?? false;
    }

    protected function prepareForValidation(): void
    {
        try {
            $normalized = NodeName::normalize($this->input('name'));
            $this->merge(['name' => $normalized['display']]);
        } catch (\InvalidArgumentException) {
            // The validation rule below returns the stable public error.
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        NodeName::normalize($value);
                    } catch (\InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'parent_id' => ['present', 'nullable', 'uuid'],
            'path' => ['prohibited'],
            'depth' => ['prohibited'],
            'order' => ['prohibited'],
            'active' => ['prohibited'],
            'institution_id' => ['prohibited'],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'has_children' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['name', 'parent_id'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "The {$field} field is not allowed.");
                }
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'NODE_CREATE_FORBIDDEN',
            'You are not allowed to create nodes.',
            403,
        ));
    }

    protected function failedValidation(Validator $validator): void
    {
        $code = count(array_diff(array_keys($validator->errors()->toArray()), ['name'])) === 0
            ? 'NODE_NAME_INVALID'
            : 'VALIDATION_FAILED';

        throw new HttpResponseException(ApiResponse::error(
            $code,
            'The node creation request is invalid.',
            422,
            $validator->errors()->toArray(),
        ));
    }
}
