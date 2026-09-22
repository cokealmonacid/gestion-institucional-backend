<?php

namespace Modules\Documents\Http\Requests;

use App\Enums\InstitutionAbility;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ListDocumentVersionNoteHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(InstitutionAbility::ViewTraceability->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'limit.integer' => 'El límite debe ser un número entero.',
            'limit.min' => 'El límite debe ser al menos 1.',
            'limit.max' => 'El límite no puede superar 100.',
            'cursor.string' => 'El cursor debe ser texto.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), ['limit', 'cursor']) as $field) {
                $validator->errors()->add($field, "El parámetro {$field} no está permitido.");
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'DOCUMENT_VERSION_NOTE_HISTORY_FORBIDDEN',
            'No tienes permiso para consultar el historial de notas.',
            403,
        ));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'VALIDATION_FAILED',
            'La solicitud de historial de notas no es válida.',
            422,
            $validator->errors()->toArray(),
        ));
    }
}
