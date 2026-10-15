<?php

namespace App\Platform\Services;

use OTPHP\TOTP;

class TotpVerifier
{
    public function __construct(private PlatformClock $clock) {}

    public function generate(): string
    {
        return TOTP::generate($this->clock)->getSecret();
    }

    public function uri(string $secret, string $email): string
    {
        $otp = TOTP::createFromSecret($secret, $this->clock);
        $otp->setLabel($email);
        $otp->setIssuer('Acervo');

        return $otp->getProvisioningUri();
    }

    public function step(string $secret, string $code): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $otp = TOTP::createFromSecret($secret, $this->clock);
        $step = intdiv($this->clock->now()->getTimestamp(), 30);
        for ($candidate = $step + 1; $candidate >= $step - 1; $candidate--) {
            if ($candidate >= 0 && $otp->verify($code, $candidate * 30)) {
                return $candidate;
            }
        }

        return null;
    }
}
