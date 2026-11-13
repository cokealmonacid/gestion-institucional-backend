<?php

namespace Tests\Feature\Contracts;

use App\Enums\InstitutionAbility;
use Tests\TestCase;

class AuthenticationOpenApiTest extends TestCase
{
    private function contract(): array
    {
        $contents = file_get_contents(base_path('openapi/v1/authentication.json'));

        $this->assertNotFalse($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_covers_authentication_and_onboarding_routes(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('4.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame([
            '/api/v1/auth/register',
            '/api/v1/auth/login',
            '/api/v1/auth/email/verification-notification',
            '/api/v1/auth/email/verify',
            '/api/v1/auth/password-recovery',
            '/api/v1/auth/password-reset',
            '/api/v1/auth/activation',
            '/api/v1/user/profile',
            '/api/v1/user/update-password',
            '/api/v1/auth/logout',
        ], array_keys($contract['paths']));
    }

    public function test_contract_documents_implemented_status_codes_and_security(): void
    {
        $paths = $this->contract()['paths'];

        $this->assertSame([201, 422, 429, 503], array_keys($paths['/api/v1/auth/register']['post']['responses']));
        $this->assertSame([200, 401, 403, 422, 429], array_keys($paths['/api/v1/auth/login']['post']['responses']));
        $this->assertSame([202, 401, 403, 429], array_keys($paths['/api/v1/auth/email/verification-notification']['post']['responses']));
        $this->assertSame([200, 401, 403, 429], array_keys($paths['/api/v1/auth/email/verify']['post']['responses']));
        $this->assertSame([202, 422, 429], array_keys($paths['/api/v1/auth/password-recovery']['post']['responses']));
        $this->assertSame([200, 400, 422, 429], array_keys($paths['/api/v1/auth/password-reset']['post']['responses']));
        $this->assertSame([200, 400, 422, 429], array_keys($paths['/api/v1/auth/activation']['post']['responses']));
        $this->assertSame([200, 401, 403], array_keys($paths['/api/v1/user/profile']['get']['responses']));
        $this->assertSame([200, 401, 403, 422, 429], array_keys($paths['/api/v1/user/update-password']['patch']['responses']));
        $this->assertSame([200, 401, 403], array_keys($paths['/api/v1/auth/logout']['post']['responses']));
        $this->assertSame([['bearerAuth' => []]], $paths['/api/v1/auth/email/verify']['post']['security']);
    }

    public function test_contract_models_pending_and_ready_sessions_without_delivery(): void
    {
        $schemas = $this->contract()['components']['schemas'];
        $user = $schemas['User'];

        $this->assertSame(['verify_email', 'create_institution', 'preparing', 'ready', 'suspended'], $schemas['Onboarding']['properties']['state']['enum']);
        $this->assertContains('email_verified', $user['required']);
        $this->assertSame('null', $user['properties']['institution']['anyOf'][1]['type']);
        $this->assertSame(array_column(InstitutionAbility::cases(), 'value'), $user['properties']['abilities']['items']['enum']);
        $this->assertSame('disabled', $schemas['DeliveryDisabledData']['properties']['delivery_status']['const']);
        $this->assertArrayNotHasKey('verification_url', $schemas['RegistrationData']['properties'] ?? []);
        $this->assertArrayNotHasKey('token', $schemas['ProfileData']['properties']);
    }
}
