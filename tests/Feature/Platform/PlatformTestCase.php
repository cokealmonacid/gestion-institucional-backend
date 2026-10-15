<?php

namespace Tests\Feature\Platform;

use App\Platform\Models\PlatformOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OTPHP\TOTP;
use Tests\TestCase;

abstract class PlatformTestCase extends TestCase
{
    use RefreshDatabase;

    protected function operator(bool $enrolled = true): PlatformOperator
    {
        return PlatformOperator::create(['name' => 'Test operator', 'email' => uniqid('operator').'@example.test', 'password' => 'Valid-test-password-123', 'verified_at' => now(), 'totp_secret' => $enrolled ? 'JBSWY3DPEHPK3PXP' : null, 'mfa_confirmed_at' => $enrolled ? now() : null]);
    }

    protected function token(PlatformOperator $operator): string
    {
        $issued = $operator->createToken('fixture', ['platform'], now()->addHour());
        $issued->accessToken->forceFill(['platform_auth_epoch' => $operator->auth_epoch, 'platform_mfa_verified_at' => now()])->save();
        $operator->withAccessToken($issued->accessToken);

        return $issued->plainTextToken;
    }

    protected function code(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at(now()->timestamp);
    }
}
