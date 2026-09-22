<?php

namespace Modules\Documents\Http\Requests;

use App\Enums\InstitutionAbility;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateDocumentVersionNoteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $raw = json_decode($this->getContent(), true);

        if (is_array($raw) && array_key_exists('note', $raw)) {
            $value = $raw['note'];
            $this->merge(['note' => is_string($value) ? trim($value) : $value]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can(InstitutionAbility::ManageComments->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'note' => ['present', 'nullable', 'string', 'max:2000', 'not_regex:/^\s*$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.present' => 'El campo nota es obligatorio.',
            'note.string' => 'La nota debe ser texto.',
            'note.max' => 'La nota no puede superar los 2.000 caracteres.',
            'note.not_regex' => 'La nota no puede estar vacía.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['note']) as $field) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "El campo {$field} no está permitido.");
                }
            }

            if (is_string($this->input('note')) && $this->input('note') === '') {
                $validator->errors()->add('note', 'La nota no puede estar vacía.');
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'DOCUMENT_VERSION_NOTE_FORBIDDEN',
            'No tienes permiso para modificar notas de versiones.',
            403,
        ));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'VALIDATION_FAILED',
            'La solicitud de nota de versión no es válida.',
            422,
            $validator->errors()->toArray(),
        ));
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }
}
