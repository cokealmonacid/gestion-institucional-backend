<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentExplorerDocumentCreateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_persisted_logical_document_in_an_accessible_node(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $node = $this->node($institution, 'Documents');
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", [
            'name' => 'Institutional regulations',
            'description' => 'Current regulations.',
            'category' => 'Regulations',
            'responsible_unit' => 'General Secretariat',
        ])->assertCreated()
            ->assertHeader('Location', url('/api/v1/documents/'.$this->documentIdFromResponse()))
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Document created successfully.')
            ->assertJsonPath('data.name', 'Institutional regulations')
            ->assertJsonPath('data.status', true)
            ->assertJsonPath('data.author_id', $user->id)
            ->assertJsonPath('data.institution_id', $institution->id)
            ->assertJsonPath('data.node_id', $node->id);

        $documentId = $response->json('data.id');
        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'author_id' => $user->id,
            'institution_id' => $institution->id,
            'node_id' => $node->id,
            'status' => true,
        ]);
        $this->assertSame([
            'id', 'name', 'description', 'category', 'responsible_unit', 'status',
            'author_id', 'institution_id', 'node_id', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_authentication_and_role_authorization_are_canonical(): void
    {
        $this->postJson('/api/v1/institution/tree-directory/example/documents', ['name' => 'Document'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');

        [$institution, $reader] = $this->institutionUser(RoleType::Reader);
        $node = $this->node($institution, 'Documents');
        Sanctum::actingAs($reader);

        $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => 'Document'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'DOCUMENT_CREATE_FORBIDDEN');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_editor_creates_a_document_in_a_valid_location(): void
    {
        [$institution, $editor] = $this->institutionUser(RoleType::Editor);
        $node = $this->node($institution, 'Documents');
        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", [
            'name' => 'Editor document',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Editor document')
            ->assertJsonPath('data.author_id', $editor->id)
            ->assertJsonPath('data.institution_id', $institution->id)
            ->assertJsonPath('data.node_id', $node->id);

        $this->assertDatabaseHas('documents', [
            'name' => 'Editor document',
            'author_id' => $editor->id,
            'institution_id' => $institution->id,
            'node_id' => $node->id,
        ]);
    }

    public function test_http_pipeline_normalizes_document_text_fields(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $node = $this->node($institution, 'Documents');
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", [
            'name' => '  Document name  ',
            'description' => '   ',
            'category' => '',
            'responsible_unit' => '  ',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Document name')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.responsible_unit', null);

        $this->assertDatabaseHas('documents', [
            'id' => $response->json('data.id'),
            'name' => 'Document name',
            'description' => null,
            'category' => null,
            'responsible_unit' => null,
        ]);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_empty_and_whitespace_only_names_fail_validation_without_creating_documents(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $node = $this->node($institution, 'Documents');
        Sanctum::actingAs($user);

        foreach (['', '   '] as $name) {
            $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => $name])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['fields' => ['name']]]);
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_missing_inactive_foreign_and_hidden_locations_are_not_available(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Editor);
        $inactive = $this->node($institution, 'Inactive', null, false);
        $inactiveParent = $this->node($institution, 'Hidden parent', null, false, 2);
        $hiddenChild = $this->node($institution, 'Hidden child', $inactiveParent, true);
        [$otherInstitution] = $this->institutionUser(RoleType::Admin);
        $foreign = $this->node($otherInstitution, 'Foreign');
        Sanctum::actingAs($user);

        foreach (['missing-node', $inactive->id, $hiddenChild->id, $foreign->id] as $nodeId) {
            $this->postJson("/api/v1/institution/tree-directory/{$nodeId}/documents", ['name' => 'Document'])
                ->assertNotFound()
                ->assertExactJson([
                    'success' => false,
                    'error' => [
                        'code' => 'DOCUMENT_LOCATION_NOT_FOUND',
                        'message' => 'The selected document location is not available.',
                    ],
                ]);
        }

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_request_validates_confirmed_fields_and_rejects_client_derived_or_additional_fields(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $node = $this->node($institution, 'Documents');
        Sanctum::actingAs($user);

        foreach ([
            [[], 'name'],
            [['name' => str_repeat('a', 256)], 'name'],
            [['name' => 'Document', 'description' => []], 'description'],
            [['name' => 'Document', 'category' => str_repeat('a', 256)], 'category'],
            [['name' => 'Document', 'responsible_unit' => str_repeat('a', 256)], 'responsible_unit'],
        ] as [$payload, $field]) {
            $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", $payload)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }

        foreach (['id', 'status', 'author_id', 'institution_id', 'node_id', 'created_at', 'updated_at', 'unexpected'] as $field) {
            $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", [
                'name' => 'Document',
                $field => $field === 'status' ? false : 'client-value',
            ])->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }

        $this->assertDatabaseCount('documents', 0);
    }

    /** @return array{Institution, User} */
    private function institutionUser(RoleType $role): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $roleModel = Rol::firstOrCreate(['type' => $role]);
        $user->roles()->attach($roleModel);

        return [$institution, $user];
    }

    private function node(Institution $institution, string $name, ?Node $parent = null, bool $active = true, int $order = 1): Node
    {
        $node = new Node;
        $node->id = $node->newUniqueId();
        $node->fill([
            'name' => $name,
            'path' => $parent === null ? $node->id : $parent->path.'/'.$node->id,
            'depth' => $parent === null ? 0 : $parent->depth + 1,
            'order' => $order,
            'active' => $active,
            'institution_id' => $institution->id,
            'parent_id' => $parent?->id,
        ]);
        $node->save();

        return $node;
    }

    private function documentIdFromResponse(): string
    {
        return Document::query()->value('id');
    }
}
