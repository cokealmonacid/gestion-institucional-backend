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

    public function test_contract_is_openapi_31_and_contains_only_the_authentication_paths(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('2.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame([
            '/api/v1/auth/login',
            '/api/v1/user/profile',
            '/api/v1/auth/logout',
        ], array_keys($contract['paths']));
    }

    public function test_contract_documents_the_approved_status_codes(): void
    {
        $contract = $this->contract();

        $this->assertSame(
            [200, 401, 403, 422],
            array_keys($contract['paths']['/api/v1/auth/login']['post']['responses']),
        );
        $this->assertSame(
            [200, 401],
            array_keys($contract['paths']['/api/v1/user/profile']['get']['responses']),
        );
        $this->assertSame(
            [200, 401],
            array_keys($contract['paths']['/api/v1/auth/logout']['post']['responses']),
        );
    }

    public function test_token_is_required_only_by_the_login_success_schema(): void
    {
        $contract = $this->contract();
        $schemas = $contract['components']['schemas'];

        $this->assertContains('token', $schemas['LoginData']['required']);
        $this->assertArrayHasKey('token', $schemas['LoginData']['properties']);
        $this->assertArrayNotHasKey('token', $schemas['User']['properties']);
        $this->assertArrayNotHasKey('token', $schemas['ProfileData']['properties']);
    }

    public function test_contract_defines_bearer_security_and_contractual_roles(): void
    {
        $contract = $this->contract();

        $this->assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'Sanctum',
        ], $contract['components']['securitySchemes']['bearerAuth']);

        $this->assertSame(
            ['admin', 'editor', 'reader'],
            $contract['components']['schemas']['User']['properties']['roles']['items']['enum'],
        );
    }

    public function test_user_requires_the_closed_canonical_abilities_projection(): void
    {
        $user = $this->contract()['components']['schemas']['User'];
        $abilities = $user['properties']['abilities'];

        $this->assertFalse($user['additionalProperties']);
        $this->assertContains('abilities', $user['required']);
        $this->assertSame('array', $abilities['type']);
        $this->assertTrue($abilities['uniqueItems']);
        $this->assertSame('string', $abilities['items']['type']);
        $this->assertSame(array_column(InstitutionAbility::cases(), 'value'), $abilities['items']['enum']);
        $this->assertSame(
            [
                InstitutionAbility::View->value,
                InstitutionAbility::ManageDocuments->value,
                InstitutionAbility::ManageVersions->value,
                InstitutionAbility::ManageTags->value,
                InstitutionAbility::TagDocuments->value,
                InstitutionAbility::ManageComments->value,
                InstitutionAbility::ViewTraceability->value,
            ],
            $user['examples'][0]['abilities'],
        );
    }
}
