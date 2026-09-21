<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class UserSearchOpenApiTest extends TestCase
{
    private function contract(): array
    {
        $contents = file_get_contents(base_path('openapi/v1/user-search.json'));

        $this->assertNotFalse($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_contains_only_the_approved_admin_operation(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('2.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame(['/api/v1/user/search'], array_keys($contract['paths']));
        $this->assertSame(['get'], array_keys($contract['paths']['/api/v1/user/search']));
    }

    public function test_contract_documents_the_effective_status_codes_and_security(): void
    {
        $contract = $this->contract();
        $operation = $contract['paths']['/api/v1/user/search']['get'];

        $this->assertSame([200, 401, 403, 422], array_keys($operation['responses']));
        $this->assertSame([['bearerAuth' => []]], $operation['security']);
        $this->assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'Sanctum',
        ], $contract['components']['securitySchemes']['bearerAuth']);
    }

    public function test_result_schema_matches_the_complete_effective_response(): void
    {
        $result = $this->contract()['components']['schemas']['UserSearchResult'];

        $this->assertFalse($result['additionalProperties']);
        $this->assertSame(['id', 'name', 'email'], $result['required']);
        $this->assertSame($result['required'], array_keys($result['properties']));
        $this->assertArrayNotHasKey('password', $result['properties']);
        $this->assertArrayNotHasKey('remember_token', $result['properties']);
        $this->assertArrayNotHasKey('active', $result['properties']);
    }

    public function test_contract_limits_search_to_the_authenticated_institution_without_a_selector(): void
    {
        $contract = $this->contract();
        $operation = $contract['paths']['/api/v1/user/search']['get'];

        $this->assertStringContainsString("authenticated admin's institution", $contract['info']['description']);
        $this->assertStringContainsString('Users from other institutions are never observable', $operation['description']);
        $this->assertSame([['$ref' => '#/components/parameters/Query']], $operation['parameters']);
        $this->assertSame(10, $contract['components']['schemas']['UserSearchData']['properties']['users']['maxItems']);
    }

    public function test_forbidden_response_has_no_success_key(): void
    {
        $forbidden = $this->contract()['components']['schemas']['ForbiddenError'];

        $this->assertSame(['message'], $forbidden['required']);
        $this->assertSame(['message'], array_keys($forbidden['properties']));
        $this->assertSame(['Forbidden.'], $forbidden['properties']['message']['enum']);
    }

    public function test_success_response_has_a_complete_example(): void
    {
        $operation = $this->contract()['paths']['/api/v1/user/search']['get'];
        $example = $operation['responses']['200']['content']['application/json']['example'];

        $this->assertSame(['success', 'data', 'message'], array_keys($example));
        $this->assertTrue($example['success']);
        $this->assertSame('Users retrieved successfully.', $example['message']);
    }
}
