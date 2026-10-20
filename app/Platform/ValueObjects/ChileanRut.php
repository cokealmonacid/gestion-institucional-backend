<?php

namespace App\Platform\ValueObjects;

use Illuminate\Validation\ValidationException;

class ChileanRut
{
    public static function normalize(string $input): string
    {
        $input = trim($input);
        if (! preg_match('/^(?:\d{1,3}(?:\.\d{3})+|\d+)-?[\dKk]$/D', $input)) {
            throw ValidationException::withMessages(['customer.rut' => ['Invalid RUT.']]);
        }
        $clean = strtoupper(str_replace(['.', '-'], '', $input));
        $check = substr($clean, -1);
        $body = ltrim(substr($clean, 0, -1), '0');
        if ($body === '' || strlen($body) > 8) {
            throw ValidationException::withMessages(['customer.rut' => ['Invalid RUT.']]);
        }
        $sum = 0;
        $factor = 2;
        foreach (str_split(strrev($body)) as $digit) {
            $sum += (int) $digit * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $result = 11 - ($sum % 11);
        $expected = match ($result) {
            11 => '0',10 => 'K',default => (string) $result
        };
        if ($check !== $expected) {
            throw ValidationException::withMessages(['customer.rut' => ['Invalid RUT.']]);
        }

        return $body.'-'.$check;
    }
}
