<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class DocumentLifecycleOpenApiTest extends TestCase
{
    private function contract(): array
    {
        return json_decode((string) file_get_contents(base_path('openapi/v1/document-lifecycle.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_publishes_exactly_the_twelve_lifecycle_operations(): void
    {
        $contract = $this->contract();
        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('2.2.0', $contract['info']['version']);

        $operations = [];
        foreach ($contract['paths'] as $path) {
            foreach ($path as $operation) {
                $operations[] = $operation['operationId'];
                $this->assertSame([['bearerAuth' => []]], $operation['security']);
            }
        }

        $this->assertSame([
            'getDocumentLifecycleDetail', 'listDocumentHistory', 'searchDocumentResponsibleOptions',
            'updateDocumentResponsible', 'listDocumentVersions',
            'createDocumentVersion', 'downloadCurrentDocumentVersion',
            'downloadDocumentVersion', 'setCurrentDocumentVersion',
            'listDocumentVersionNotes', 'listDocumentVersionNoteHistory',
            'updateDocumentVersionNote',
        ], $operations);
    }

    public function test_history_contract_is_cursor_paginated_closed_and_private(): void
    {
        $contract = $this->contract();
        $operation = $contract['paths']['/api/v1/documents/{document_id}/history']['get'];
        $parameters = collect($operation['parameters'])->keyBy(fn (array $parameter) => $parameter['name'] ?? 'document_id');

        $this->assertSame(20, $parameters['limit']['schema']['default']);
        $this->assertSame(100, $parameters['limit']['schema']['maximum']);
        $this->assertSame('string', $parameters['cursor']['schema']['type']);
        $this->assertSame([200, 401, 403, 404, 422], array_keys($operation['responses']));

        foreach (['DocumentEventBase', 'DocumentEventActor', 'DocumentEventVersion', 'DocumentHistorySuccess'] as $schema) {
            $this->assertFalse($contract['components']['schemas'][$schema]['additionalProperties']);
        }
        $event = $contract['components']['schemas']['DocumentEvent'];
        $this->assertSame([
            'document.created', 'document.version_uploaded', 'document.current_version_changed',
            'document.responsible_assigned', 'document.responsible_changed', 'document.responsible_removed',
            'document.version_note_updated', 'document.version_note_cleared',
        ], array_keys($event['discriminator']['mapping']));
        $this->assertCount(8, $event['oneOf']);
        $this->assertSame([true], $contract['components']['schemas']['VersionUploadedDetail']['properties']['became_current']['oneOf'][0]['enum']);
        $this->assertContains('new_version', $contract['components']['schemas']['CurrentVersionChangedDetail']['required']);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        foreach (['origin', 'source_type', 'source_id', 'institution_id', 'node_id', 'email', 'url'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $encoded);
        }
    }

    public function test_version_notes_are_closed_paginated_and_content_private(): void
    {
        $contract = $this->contract();
        $history = $contract['paths']['/api/v1/documents/{document_id}/versions/{version_id}/note/history']['get'];
        $parameters = collect($history['parameters'])->keyBy(fn (array $parameter) => $parameter['name'] ?? 'path');

        $this->assertSame(20, $parameters['limit']['schema']['default']);
        $this->assertSame(100, $parameters['limit']['schema']['maximum']);
        $this->assertSame([200, 401, 403, 404, 422], array_keys($history['responses']));

        $request = $contract['components']['schemas']['UpdateVersionNoteRequest'];
        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['note'], $request['required']);
        $this->assertSame(2000, $request['properties']['note']['maxLength']);

        foreach (['VersionNote', 'VersionNoteActor', 'VersionNoteTransition', 'VersionNoteHistorySuccess'] as $schema) {
            $this->assertFalse($contract['components']['schemas'][$schema]['additionalProperties']);
        }
        $actor = json_encode($contract['components']['schemas']['VersionNoteActor'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('email', $actor);
        $eventDetail = $contract['components']['schemas']['VersionNoteChangedDetail'];
        $this->assertSame([], $eventDetail['properties']);
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
        $this->assertFalse($detail['additionalProperties']);
        foreach (['responsible', 'responsibility_revision'] as $field) {
            $this->assertContains($field, $detail['required']);
            $this->assertArrayHasKey($field, $detail['properties']);
        }
        foreach (['ResponsibleSummary', 'ResponsibleOption', 'UpdateResponsibilityRequest', 'ResponsibilityData'] as $schema) {
            $this->assertFalse($contract['components']['schemas'][$schema]['additionalProperties']);
        }
        foreach (['401', '403', '404', '409', '422', '500'] as $status) {
            $this->assertArrayHasKey($status, $contract['paths']['/api/v1/documents/{document_id}/responsible']['patch']['responses']);
        }
        foreach (['author_id', 'institution_id', 'node_id'] as $legacyField) {
            $this->assertContains($legacyField, $detail['required']);
            $this->assertArrayHasKey($legacyField, $detail['properties']);
        }

        foreach (['/api/v1/documents/{document_id}/download', '/api/v1/documents/{document_id}/versions/{version_id}/download'] as $path) {
            $this->assertArrayHasKey('500', $contract['paths'][$path]['get']['responses']);
        }
    }
}
