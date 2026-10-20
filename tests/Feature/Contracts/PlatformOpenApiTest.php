<?php

namespace Tests\Feature\Contracts;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlatformOpenApiTest extends TestCase
{
    public function test_ordinary_role_and_activity_contracts_are_closed_and_keep_recovery_separate(): void
    {
        $doc = json_decode(file_get_contents(base_path('openapi/v1/platform-administration.json')), true, 512, JSON_THROW_ON_ERROR);
        $schemas = $doc['components']['schemas'];
        $this->assertFalse($schemas['InstitutionalRoleChange']['additionalProperties']);
        $this->assertSame(['admin', 'editor', 'reader'], $schemas['InstitutionalRoleChange']['properties']['role']['enum']);
        $this->assertContains('confirmation', $schemas['InstitutionalRoleChange']['required']);
        $this->assertArrayNotHasKey('active', $schemas['InstitutionalRoleChange']['properties']);
        $this->assertArrayNotHasKey('role', $schemas['InstitutionalActivityChange']['properties']);
        $this->assertFalse($schemas['PlatformRoleImpact']['additionalProperties']);
        $this->assertArrayNotHasKey('documents', $schemas['PlatformRoleImpact']['properties']);
        foreach (Route::getRoutes() as $route) {
            if (preg_match('#^api/v1/platform/institutions/\{id\}/users/\{userId\}/#', $route->uri())) {
                $this->assertContains('platform.can:platform.user_access', $route->middleware());
            }
        }
    }

    public function test_contracts_cover_exactly_completed_platform_routes(): void
    {
        $documented = [];
        foreach (['platform-authentication', 'platform-administration'] as $name) {
            $doc = json_decode(file_get_contents(base_path('openapi/v1/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('3.1.0', $doc['openapi']);
            $this->assertSame($name === 'platform-authentication' ? '1.1.0' : '3.4.0', $doc['info']['version']);
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
        $this->assertContains('GET /api/v1/platform/institutions/{id}/administrators', $documented);
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

    public function test_administrator_directory_addition_is_closed_and_metadata_only(): void
    {
        $doc = json_decode(file_get_contents(base_path('openapi/v1/platform-administration.json')), true, 512, JSON_THROW_ON_ERROR);
        $schema = $doc['components']['schemas']['AdministratorListItem'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['user_id', 'name', 'email', 'active', 'activation_status', 'access_revision'], array_keys($schema['properties']));
        $this->assertSame($schema['required'], array_keys($schema['properties']));
    }

    public function test_user_directory_is_closed_metadata_with_server_side_filters_and_all_roles(): void
    {
        $doc = json_decode(file_get_contents(base_path('openapi/v1/platform-administration.json')), true, 512, JSON_THROW_ON_ERROR);
        $schema = $doc['components']['schemas']['InstitutionUserListItem'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['user_id', 'name', 'email', 'institution_id', 'institution_name', 'roles', 'active', 'email_verified', 'access_revision', 'invitation'], array_keys($schema['properties']));
        $this->assertSame(['admin', 'editor', 'reader'], $schema['properties']['roles']['items']['enum']);
        $this->assertSame(['q', 'page', 'per_page', 'institution_id', 'role', 'active', 'user_id'], array_column($doc['paths']['/api/v1/platform/users']['get']['parameters'], 'name'));
    }

    public function test_recovery_contracts_expose_purpose_bound_verification_and_durable_safe_operation_states(): void
    {
        $admin = json_decode(file_get_contents(base_path('openapi/v1/platform-administration.json')), true, 512, JSON_THROW_ON_ERROR);
        $auth = json_decode(file_get_contents(base_path('openapi/v1/platform-authentication.json')), true, 512, JSON_THROW_ON_ERROR);
        $schema = $admin['components']['schemas']['AccessRecovery'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['awaiting_verification', 'verified', 'completed', 'cancelled', 'expired'], $schema['properties']['state']['enum']);
        foreach (['verification_hash', 'prepared_password', 'fingerprint', 'reserved_email'] as $field) {
            $this->assertArrayNotHasKey($field, $schema['properties']);
        }
        foreach (['complete', 'cancel', 'resend'] as $action) {
            $this->assertArrayHasKey('/api/v1/platform/access-recoveries/{id}/'.$action, $admin['paths']);
        }
        $this->assertSame([], $auth['paths']['/api/v1/platform/auth/access-recovery-verification']['post']['security']);
        foreach ($admin['components']['schemas']['AccessRecoveryRequest']['oneOf'] as $request) {
            $this->assertFalse($request['additionalProperties']);
            $this->assertContains('authority', $request['required']);
            $this->assertContains('confirmation', $request['required']);
        }
    }
}
