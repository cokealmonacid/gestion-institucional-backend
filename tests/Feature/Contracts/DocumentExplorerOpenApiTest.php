<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class DocumentExplorerOpenApiTest extends TestCase
{
    private function contract(): array
    {
        $contents = file_get_contents(base_path('openapi/v1/document-explorer.json'));

        $this->assertNotFalse($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_contains_only_the_four_approved_read_operations(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('1.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame([
            '/api/v1/institution/tree-directory',
            '/api/v1/institution/tree-directory/{node_id}',
            '/api/v1/institution/tree-directory/{node_id}/children',
            '/api/v1/institution/tree-directory/{node_id}/documents',
        ], array_keys($contract['paths']));

        foreach ($contract['paths'] as $path) {
            $this->assertSame(['get'], array_keys($path));
        }
    }

    public function test_contract_documents_the_effective_status_codes_and_security(): void
    {
        $contract = $this->contract();

        $this->assertSame(
            [200, 401],
            array_keys($contract['paths']['/api/v1/institution/tree-directory']['get']['responses']),
        );

        foreach ([
            '/api/v1/institution/tree-directory/{node_id}',
            '/api/v1/institution/tree-directory/{node_id}/children',
            '/api/v1/institution/tree-directory/{node_id}/documents',
        ] as $path) {
            $operation = $contract['paths'][$path]['get'];
            $this->assertSame([200, 401, 404], array_keys($operation['responses']));
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
        }

        $this->assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'Sanctum',
        ], $contract['components']['securitySchemes']['bearerAuth']);
    }

    public function test_node_schema_matches_the_complete_effective_response(): void
    {
        $node = $this->contract()['components']['schemas']['Node'];

        $this->assertFalse($node['additionalProperties']);
        $this->assertSame([
            'id',
            'name',
            'path',
            'depth',
            'order',
            'active',
            'institution_id',
            'parent_id',
            'created_at',
            'updated_at',
            'has_children',
        ], $node['required']);
        $this->assertSame($node['required'], array_keys($node['properties']));
        $this->assertSame('integer', $node['properties']['active']['type']);
        $this->assertSame([1], $node['properties']['active']['enum']);
        $this->assertSame('integer', $node['properties']['depth']['type']);
        $this->assertSame('string', $node['properties']['order']['type']);
        $this->assertSame('boolean', $node['properties']['has_children']['type']);
        $this->assertSame(['string', 'null'], $node['properties']['parent_id']['type']);
        $this->assertSame(['string', 'null'], $node['properties']['created_at']['type']);
        $this->assertSame(['string', 'null'], $node['properties']['updated_at']['type']);
    }

    public function test_document_schema_matches_the_complete_effective_response(): void
    {
        $document = $this->contract()['components']['schemas']['Document'];

        $this->assertFalse($document['additionalProperties']);
        $this->assertSame([
            'id',
            'name',
            'description',
            'category',
            'responsible_unit',
            'status',
            'author_id',
            'institution_id',
            'node_id',
            'created_at',
            'updated_at',
        ], $document['required']);
        $this->assertSame($document['required'], array_keys($document['properties']));
        $this->assertSame('boolean', $document['properties']['status']['type']);
        $this->assertSame([true], $document['properties']['status']['enum']);
        $this->assertSame(['string', 'null'], $document['properties']['author_id']['type']);
        $this->assertSame('string', $document['properties']['node_id']['type']);
        $this->assertSame('uuid', $document['properties']['node_id']['format']);
    }

    public function test_every_operation_has_a_complete_success_example(): void
    {
        $contract = $this->contract();

        foreach ($contract['paths'] as $path) {
            $operation = $path['get'];
            $example = $operation['responses']['200']['content']['application/json']['example'];

            $this->assertSame(['success', 'data', 'message'], array_keys($example));
            $this->assertTrue($example['success']);
            $this->assertIsString($example['message']);
        }
    }
}
