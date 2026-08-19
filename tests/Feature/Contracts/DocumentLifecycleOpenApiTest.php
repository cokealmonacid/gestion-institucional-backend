<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class DocumentLifecycleOpenApiTest extends TestCase
{
    private function contract(): array
    {
        return json_decode((string) file_get_contents(base_path('openapi/v1/document-lifecycle.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_publishes_exactly_the_six_lifecycle_operations(): void
    {
        $contract = $this->contract();
        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('1.0.0', $contract['info']['version']);

        $operations = [];
        foreach ($contract['paths'] as $path) {
            foreach ($path as $operation) {
                $operations[] = $operation['operationId'];
                $this->assertSame([['bearerAuth' => []]], $operation['security']);
            }
        }

        $this->assertSame([
            'getDocumentLifecycleDetail', 'listDocumentVersions',
            'createDocumentVersion', 'downloadCurrentDocumentVersion',
            'downloadDocumentVersion', 'setCurrentDocumentVersion',
        ], $operations);
    }

    public function test_contract_documents_multipart_binary_public_shapes_and_errors(): void
    {
        $contract = $this->contract();
        $create = $contract['paths']['/api/v1/documents/{document_id}/versions']['post'];
        $this->assertArrayHasKey('multipart/form-data', $create['requestBody']['content']);
        $this->assertSame(26214400, $create['requestBody']['content']['multipart/form-data']['schema']['properties']['file']['maxLength']);
        $this->assertArrayHasKey('Location', $create['responses']['201']['headers']);

        foreach (['/api/v1/documents/{document_id}/download', '/api/v1/documents/{document_id}/versions/{version_id}/download'] as $path) {
            $this->assertSame('binary', $contract['paths'][$path]['get']['responses']['200']['content']['application/octet-stream']['schema']['format']);
        }

        $encoded = json_encode($contract['components']['schemas'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"url"', $encoded);
        foreach (['DocumentDetail', 'DocumentVersion', 'Error'] as $schema) {
            $this->assertArrayHasKey($schema, $contract['components']['schemas']);
        }

        $detail = $contract['components']['schemas']['DocumentDetail'];
        foreach (['author_id', 'institution_id', 'node_id'] as $legacyField) {
            $this->assertContains($legacyField, $detail['required']);
            $this->assertArrayHasKey($legacyField, $detail['properties']);
        }

        foreach (['/api/v1/documents/{document_id}/download', '/api/v1/documents/{document_id}/versions/{version_id}/download'] as $path) {
            $this->assertArrayHasKey('500', $contract['paths'][$path]['get']['responses']);
        }
    }
}
