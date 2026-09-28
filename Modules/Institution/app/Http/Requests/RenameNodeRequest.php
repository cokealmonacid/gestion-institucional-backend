<?php

namespace Modules\Institution\Http\Requests;

use App\Enums\InstitutionAbility;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Nodes\Support\NodeName;

class RenameNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(InstitutionAbility::ManageNodes->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        try {
            $this->merge(['name' => NodeName::normalize($this->input('name'))['display']]);
        } catch (\InvalidArgumentException) {
            // The rule below emits the stable public error.
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    NodeName::normalize($value);
                } catch (\InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['name']) as $field) {
                $validator->errors()->add($field, "The {$field} field is not allowed.");
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error('NODE_RENAME_FORBIDDEN', 'You are not allowed to rename nodes.', 403));
    }

    protected function failedValidation(Validator $validator): void
    {
        $onlyName = count(array_diff(array_keys($validator->errors()->toArray()), ['name'])) === 0;
        throw new HttpResponseException(ApiResponse::error(
            $onlyName ? 'NODE_NAME_INVALID' : 'VALIDATION_FAILED',
            'The node rename request is invalid.',
            422,
            $validator->errors()->toArray(),
        ));
    }
}
