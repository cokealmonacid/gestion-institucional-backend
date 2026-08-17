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

    public function test_contract_contains_the_four_reads_and_the_canonical_create_operation(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('2.1.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame([
            '/api/v1/institution/tree-directory',
            '/api/v1/institution/tree-directory/{node_id}',
            '/api/v1/institution/tree-directory/{node_id}/children',
            '/api/v1/institution/tree-directory/{node_id}/documents',
        ], array_keys($contract['paths']));

        $this->assertSame(['get', 'post'], array_keys($contract['paths']['/api/v1/institution/tree-directory']));
        $this->assertSame(['get'], array_keys($contract['paths']['/api/v1/institution/tree-directory/{node_id}']));
        $this->assertSame(['get'], array_keys($contract['paths']['/api/v1/institution/tree-directory/{node_id}/children']));
        $this->assertSame(['get', 'post'], array_keys($contract['paths']['/api/v1/institution/tree-directory/{node_id}/documents']));
    }

    public function test_contract_documents_the_effective_status_codes_and_security(): void
    {
        $contract = $this->contract();

        $this->assertSame(
            [200, 401],
            array_keys($contract['paths']['/api/v1/institution/tree-directory']['get']['responses']),
        );

        $create = $contract['paths']['/api/v1/institution/tree-directory']['post'];
        $this->assertSame([201, 401, 403, 404, 409, 422], array_keys($create['responses']));
        $this->assertSame([['bearerAuth' => []]], $create['security']);

        $createDocument = $contract['paths']['/api/v1/institution/tree-directory/{node_id}/documents']['post'];
        $this->assertSame('createInstitutionDocument', $createDocument['operationId']);
        $this->assertSame([201, 401, 403, 404, 422], array_keys($createDocument['responses']));
        $this->assertSame([['bearerAuth' => []]], $createDocument['security']);

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
        $this->assertSame('integer', $node['properties']['order']['type']);
        $this->assertSame(1, $node['properties']['order']['minimum']);
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

    public function test_create_request_requires_only_name_and_nullable_parent_id(): void
    {
        $contract = $this->contract();
        $request = $contract['components']['schemas']['CreateNodeRequest'];

        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['name', 'parent_id'], $request['required']);
        $this->assertSame(['name', 'parent_id'], array_keys($request['properties']));
        $this->assertSame(1, $request['properties']['name']['minLength']);
        $this->assertSame(255, $request['properties']['name']['maxLength']);
        $this->assertSame(['string', 'null'], $request['properties']['parent_id']['type']);

        $operation = $contract['paths']['/api/v1/institution/tree-directory']['post'];
        $this->assertTrue($operation['requestBody']['required']);
        $this->assertArrayHasKey('root', $operation['requestBody']['content']['application/json']['examples']);
        $this->assertArrayHasKey('child', $operation['requestBody']['content']['application/json']['examples']);
        $this->assertArrayHasKey('Location', $operation['responses']['201']['headers']);
    }

    public function test_document_create_request_contains_only_confirmed_user_fields(): void
    {
        $contract = $this->contract();
        $request = $contract['components']['schemas']['CreateDocumentRequest'];

        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['name'], $request['required']);
        $this->assertSame(['name', 'description', 'category', 'responsible_unit'], array_keys($request['properties']));
        $this->assertSame(255, $request['properties']['name']['maxLength']);
        $this->assertSame(255, $request['properties']['category']['maxLength']);
        $this->assertSame(255, $request['properties']['responsible_unit']['maxLength']);
        $this->assertStringContainsString('trims leading and trailing whitespace', $request['properties']['name']['description']);
        $this->assertStringContainsString('converts empty strings to null', $request['properties']['name']['description']);
        $this->assertStringContainsString('not NFC-normalized', $request['properties']['name']['description']);
        $this->assertStringContainsString('case-normalized', $request['properties']['name']['description']);
        $this->assertStringContainsString('uniqueness rule', $request['properties']['name']['description']);

        foreach (['description', 'category', 'responsible_unit'] as $field) {
            $this->assertStringContainsString('trims leading and trailing whitespace', $request['properties'][$field]['description']);
            $this->assertStringContainsString('converts empty strings to null', $request['properties'][$field]['description']);
            $this->assertStringContainsString('whitespace-only input is stored as null', $request['properties'][$field]['description']);
            $this->assertStringContainsString('No additional document-domain normalization', $request['properties'][$field]['description']);
        }

        $operation = $contract['paths']['/api/v1/institution/tree-directory/{node_id}/documents']['post'];
        $this->assertTrue($operation['requestBody']['required']);
        $this->assertArrayHasKey('Location', $operation['responses']['201']['headers']);
        $this->assertSame(
            'Location of the created document resource.',
            $operation['responses']['201']['headers']['Location']['description'],
        );
        $this->assertArrayNotHasKey('409', $operation['responses']);
    }

    public function test_legacy_create_path_is_not_published_as_a_post_operation(): void
    {
        $contract = $this->contract();

        $this->assertArrayNotHasKey('post', $contract['paths']['/api/v1/institution/tree-directory/{node_id}']);
    }
}
