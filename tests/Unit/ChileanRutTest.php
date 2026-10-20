<?php

namespace Tests\Unit;

use App\Platform\ValueObjects\ChileanRut;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChileanRutTest extends TestCase
{
    public function test_normalizes_check_digit_k_and_leading_zeroes(): void
    {
        $this->assertSame('6-K', ChileanRut::normalize('0006-k'));
        $this->assertSame('12345678-5', ChileanRut::normalize('123456785'));
    }

    public function test_rejects_zero_and_unknown_characters(): void
    {
        foreach (['0-0', '12a345678-5', '12.34.5678-5', '12345678-4', '123456789-0'] as $input) {
            try {
                ChileanRut::normalize($input);
                $this->fail('Invalid fixture accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
