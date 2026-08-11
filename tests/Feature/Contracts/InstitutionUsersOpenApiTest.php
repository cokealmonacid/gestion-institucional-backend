<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class InstitutionUsersOpenApiTest extends TestCase
{
    private function contract(): array
    {
        $contents = file_get_contents(base_path('openapi/v1/institution-users.json'));

        $this->assertNotFalse($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_contains_only_the_four_approved_admin_operations(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('1.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame([
            '/api/v1/institution/users',
            '/api/v1/institution/users/role',
        ], array_keys($contract['paths']));

        $this->assertSame(['get', 'post', 'patch'], array_keys($contract['paths']['/api/v1/institution/users']));
        $this->assertSame(['patch'], array_keys($contract['paths']['/api/v1/institution/users/role']));
    }

    public function test_contract_documents_the_effective_status_codes_and_security(): void
    {
        $contract = $this->contract();

        $this->assertSame(
            [200, 401, 403],
            array_keys($contract['paths']['/api/v1/institution/users']['get']['responses']),
        );

        foreach ([
            $contract['paths']['/api/v1/institution/users']['post'],
            $contract['paths']['/api/v1/institution/users']['patch'],
            $contract['paths']['/api/v1/institution/users/role']['patch'],
        ] as $operation) {
            $this->assertSame([200, 401, 403, 422], array_keys($operation['responses']));
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
        }

        $this->assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'Sanctum',
        ], $contract['components']['securitySchemes']['bearerAuth']);
    }

    public function test_user_schema_matches_the_complete_effective_response(): void
    {
        $user = $this->contract()['components']['schemas']['User'];

        $this->assertFalse($user['additionalProperties']);
        $this->assertSame([
            'id',
            'name',
            'email',
            'role',
            'created_at',
            'active',
        ], $user['required']);
        $this->assertSame($user['required'], array_keys($user['properties']));
        $this->assertArrayNotHasKey('password', $user['properties']);
        $this->assertArrayNotHasKey('remember_token', $user['properties']);
        $this->assertSame('boolean', $user['properties']['active']['type']);
        $this->assertSame('string', $user['properties']['created_at']['type']);
    }

    public function test_forbidden_response_has_no_success_key(): void
    {
        $forbidden = $this->contract()['components']['schemas']['ForbiddenError'];

        $this->assertSame(['message'], $forbidden['required']);
        $this->assertSame(['message'], array_keys($forbidden['properties']));
        $this->assertSame(['Forbidden.'], $forbidden['properties']['message']['enum']);
    }

    public function test_every_operation_has_a_complete_success_example(): void
    {
        $contract = $this->contract();

        foreach ([
            $contract['paths']['/api/v1/institution/users']['get'],
            $contract['paths']['/api/v1/institution/users']['post'],
            $contract['paths']['/api/v1/institution/users']['patch'],
            $contract['paths']['/api/v1/institution/users/role']['patch'],
        ] as $operation) {
            $example = $operation['responses']['200']['content']['application/json']['example'];

            $this->assertSame(['success', 'data', 'message'], array_keys($example));
            $this->assertTrue($example['success']);
            $this->assertIsString($example['message']);
        }
    }
}
