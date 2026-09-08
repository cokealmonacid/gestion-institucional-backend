<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class InstitutionDocumentsOpenApiTest extends TestCase
{
    private function contract(): array
    {
        return json_decode(
            file_get_contents(base_path('openapi/v1/institution-documents.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_contract_contains_only_the_single_document_listing_operation(): void
    {
        $contract = $this->contract();

        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('1.0.0', $contract['info']['version']);
        $this->assertArrayNotHasKey('servers', $contract);
        $this->assertSame(['/api/v1/institution/documents'], array_keys($contract['paths']));
        $this->assertSame(['get'], array_keys($contract['paths']['/api/v1/institution/documents']));
    }

    public function test_operation_documents_security_status_codes_and_a_complete_success_example(): void
    {
        $operation = $this->contract()['paths']['/api/v1/institution/documents']['get'];

        $this->assertSame('listInstitutionDocuments', $operation['operationId']);
        $this->assertSame([['bearerAuth' => []]], $operation['security']);
        $this->assertSame([200, 401, 403], array_keys($operation['responses']));

        $example = $operation['responses']['200']['content']['application/json']['example'];
        $this->assertSame(['success', 'data', 'message'], array_keys($example));
        $this->assertTrue($example['success']);
        $this->assertSame([
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total',
        ], array_keys($example['data']));
    }

    public function test_parameters_are_per_page_and_status(): void
    {
        $contract = $this->contract();
        $parameters = $contract['paths']['/api/v1/institution/documents']['get']['parameters'];

        $this->assertSame([
            '#/components/parameters/PerPage',
            '#/components/parameters/Status',
        ], array_map(fn (array $parameter): string => $parameter['$ref'], $parameters));

        $this->assertSame(['PerPage', 'Status'], array_keys($contract['components']['parameters']));
        $this->assertSame('per_page', $contract['components']['parameters']['PerPage']['name']);
        $this->assertSame('status', $contract['components']['parameters']['Status']['name']);
        $this->assertSame('boolean', $contract['components']['parameters']['Status']['schema']['type']);
    }

    public function test_document_page_shape_is_closed_and_uses_the_lifecycle_summary(): void
    {
        $schemas = $this->contract()['components']['schemas'];

        $page = $schemas['DocumentPage'];
        $this->assertFalse($page['additionalProperties']);
        $this->assertSame([
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total',
        ], $page['required']);

        $document = $schemas['Document'];
        $this->assertFalse($document['additionalProperties']);
        $this->assertSame([
            'id',
            'name',
            'description',
            'category',
            'responsible_unit',
            'status',
            'author',
            'node_id',
            'created_at',
            'updated_at',
            'lifecycle',
        ], $document['required']);
        $this->assertSame('boolean', $document['properties']['status']['type']);
        $this->assertSame(['string', 'null'], $document['properties']['author']['type']);

        $summary = $schemas['DocumentLifecycleSummary'];
        $this->assertFalse($summary['additionalProperties']);
        $this->assertSame(['version_count', 'has_current_version', 'capabilities'], $summary['required']);

        $capabilities = $schemas['DocumentLifecycleSummaryCapabilities'];
        $this->assertFalse($capabilities['additionalProperties']);
        $this->assertSame(['can_download', 'can_upload_version'], $capabilities['required']);
    }
}
