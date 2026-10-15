<?php

namespace Tests\Feature\Contracts;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlatformOpenApiTest extends TestCase
{
    public function test_contracts_cover_exactly_completed_platform_routes(): void
    {
        $documented = [];
        foreach (['platform-authentication', 'platform-administration'] as $name) {
            $doc = json_decode(file_get_contents(base_path('openapi/v1/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('3.1.0', $doc['openapi']);
            $this->assertSame($name === 'platform-authentication' ? '1.0.0' : '3.0.0', $doc['info']['version']);
            $this->assertArrayNotHasKey('servers', $doc);
            foreach ($doc['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    $documented[] = strtoupper($method).' '.$path;
                    $this->assertArrayHasKey('responses', $operation);
                }
            }
            $this->assertFalse($doc['components']['schemas']['Error']['additionalProperties']);
        }
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/platform/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $runtime[] = $method.' /'.$route->uri();
                }
            }
        }
        sort($runtime);
        sort($documented);
        $this->assertSame($runtime, $documented);
        $this->assertContains('POST /api/v1/platform/institutions', $documented);
        $this->assertContains('POST /api/v1/platform/institutions/{id}/administrator-invitations', $documented);
        $this->assertContains('GET /api/v1/platform/operations/{key}', $documented);
        $this->artisan('api:contract:audit')->expectsOutputToContain('0 undocumented; 0 registry issue(s).')->assertSuccessful();
    }

    public function test_authentication_documents_restricted_challenges_and_closed_payloads(): void
    {
        $doc = json_decode(file_get_contents(base_path('openapi/v1/platform-authentication.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $doc['paths']['/api/v1/platform/auth/login']['post']['security']);
        $this->assertArrayHasKey('202', $doc['paths']['/api/v1/platform/auth/login']['post']['responses']);
        $this->assertArrayNotHasKey('200', $doc['paths']['/api/v1/platform/auth/login']['post']['responses']);
        $this->assertSame([['platformBearer' => []]], $doc['security']);
        $this->assertFalse($doc['components']['schemas']['PasswordToken']['additionalProperties']);
        $proof = $doc['paths']['/api/v1/platform/auth/mfa/challenge']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertCount(2, $proof['oneOf']);
        $this->assertFalse($proof['oneOf'][0]['additionalProperties']);
    }

    public function test_administration_marks_limits_enforced_and_preserves_clp_precision(): void
    {
        $doc = json_decode(file_get_contents(base_path('openapi/v1/platform-administration.json')), true, 512, JSON_THROW_ON_ERROR);
        $schemas = $doc['components']['schemas'];
        $this->assertSame('enforced', $schemas['Revision']['properties']['quota_enforcement']['const']);
        $this->assertSame('string', $schemas['AgreementRequest']['properties']['price_clp']['type']);
        $this->assertSame(['integer', 'null'], $schemas['Limits']['properties']['admin']['type']);
        $this->assertSame(['admin', 'editor', 'reader'], $schemas['Limits']['required']);
    }

    public function test_onboarding_contracts_are_closed_idempotent_and_exclude_client_control_of_access(): void
    {
        $doc = json_decode(file_get_contents(base_path('openapi/v1/platform-administration.json')), true, 512, JSON_THROW_ON_ERROR);
        $schemas = $doc['components']['schemas'];
        $this->assertFalse($schemas['OnboardingRequest']['additionalProperties']);
        $this->assertSame(['institution', 'customer', 'initial_admin', 'contract'], $schemas['OnboardingRequest']['required']);
        $this->assertSame(['name', 'email'], array_keys($schemas['OnboardingRequest']['properties']['initial_admin']['properties']));
        $this->assertFalse($schemas['NewAdministratorRequest']['additionalProperties']);
        $this->assertArrayNotHasKey('expected_revision', $schemas['OnboardingContract']['properties']);
        $this->assertContains('current_contract', $schemas['InstitutionDetail']['required']);
        foreach (['/api/v1/platform/institutions', '/api/v1/platform/institutions/{id}/administrator-invitations'] as $path) {
            $operation = $doc['paths'][$path]['post'];
            $headers = collect($operation['parameters'])->where('in', 'header')->keyBy('name');
            $this->assertTrue($headers['Idempotency-Key']['required']);
            $this->assertSame('uuid', $headers['Idempotency-Key']['schema']['format']);
            $this->assertSame(['true', 'false'], $operation['responses'][201]['headers']['Idempotency-Replayed']['schema']['enum']);
            $this->assertArrayHasKey(409, $operation['responses']);
        }
        $parameters = $doc['paths']['/api/v1/platform/operations/{key}']['get']['parameters'];
        $this->assertSame(['key', 'operation_type'], array_column($parameters, 'name'));
        $this->assertSame(['institution_onboarding', 'administrator_invitation'], $parameters[1]['schema']['enum']);
    }
}
