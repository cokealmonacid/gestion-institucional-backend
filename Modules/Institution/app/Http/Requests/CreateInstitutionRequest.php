<?php

namespace Modules\Institution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateInstitutionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_keys($this->all()) as $field) {
                if ($field !== 'name') {
                    $validator->errors()->add($field, 'This field is not allowed.');
                }
            }
        });
    }
}
