<?php

namespace Tests\Unit;

use App\Platform\Services\TotpVerifier;
use OTPHP\TOTP;
use Tests\TestCase;

class TotpVerifierTest extends TestCase
{
    public function test_uses_fixed_window_with_injected_clock_and_does_not_accept_older_codes(): void
    {
        $this->freezeTime();
        $secret = 'JBSWY3DPEHPK3PXP';
        $otp = TOTP::createFromSecret($secret);
        $step = intdiv(now()->timestamp, 30);
        $verifier = app(TotpVerifier::class);
        foreach ([-1, 0, 1] as $offset) {
            $this->assertSame($step + $offset, $verifier->step($secret, $otp->at(($step + $offset) * 30)));
        }
        $this->assertNull($verifier->step($secret, $otp->at(($step - 2) * 30)));
        $this->assertNull($verifier->step($secret, $otp->at(($step + 2) * 30)));
        $this->assertNull($verifier->step($secret, '123'));
        $this->assertStringContainsString('issuer=Acervo', $verifier->uri($secret, 'fixture@example.test'));
    }
}
