<?php

namespace App\Rules;

use Closure;

final class UserPassword
{
    /** @return array<int, mixed> */
    public static function rules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            'min:8',
            'max:72',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The '.$attribute.' field must not exceed 72 bytes.');
                }
            },
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}
