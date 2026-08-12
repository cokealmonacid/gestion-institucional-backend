<?php

namespace Modules\Documents\Http\Requests;

use App\Enums\RoleType;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->whereIn('type', [RoleType::Admin->value, RoleType::Editor->value])
            ->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'responsible_unit' => ['nullable', 'string', 'max:255'],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'author_id' => ['prohibited'],
            'institution_id' => ['prohibited'],
            'node_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['name', 'description', 'category', 'responsible_unit'];

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
            'DOCUMENT_CREATE_FORBIDDEN',
            'You are not allowed to create documents.',
            403,
        ));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'VALIDATION_FAILED',
            'The document creation request is invalid.',
            422,
            $validator->errors()->toArray(),
        ));
    }
}
