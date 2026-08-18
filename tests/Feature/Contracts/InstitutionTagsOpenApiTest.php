<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class InstitutionTagsOpenApiTest extends TestCase
{
    private function contract(): array
    {
        return json_decode(
            file_get_contents(base_path('openapi/v1/institution-tags.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_contract_contains_only_the_three_approved_tag_operations(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('1.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame([
            '/api/v1/institution/tag',
            '/api/v1/institution/tag/{tag_id}',
        ], array_keys($contract['paths']));
        $this->assertSame(['get', 'post'], array_keys($contract['paths']['/api/v1/institution/tag']));
        $this->assertSame(['delete'], array_keys($contract['paths']['/api/v1/institution/tag/{tag_id}']));
    }

    public function test_operations_document_security_status_codes_and_complete_success_examples(): void
    {
        $contract = $this->contract();
        $operations = [
            $contract['paths']['/api/v1/institution/tag']['get'],
            $contract['paths']['/api/v1/institution/tag']['post'],
            $contract['paths']['/api/v1/institution/tag/{tag_id}']['delete'],
        ];

        $this->assertSame([200, 401, 403], array_keys($operations[0]['responses']));
        $this->assertSame([200, 401, 403, 422, 500], array_keys($operations[1]['responses']));
        $this->assertSame([200, 401, 403, 422, 500], array_keys($operations[2]['responses']));

        foreach ($operations as $operation) {
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $example = $operation['responses']['200']['content']['application/json']['example'];
            $this->assertSame(['success', 'data', 'message'], array_keys($example));
            $this->assertTrue($example['success']);
        }
    }

    public function test_public_tag_shapes_are_closed_and_do_not_expose_institution_ids(): void
    {
        $schemas = $this->contract()['components']['schemas'];

        foreach (['Tag', 'CreateTagData'] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties']);
            $this->assertArrayNotHasKey('institution_id', $schemas[$name]['properties']);
        }
        $this->assertFalse($schemas['CreateTagRequest']['additionalProperties']);
        $this->assertSame(['institution_id', 'name'], $schemas['CreateTagRequest']['required']);
    }
}
