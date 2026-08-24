<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentExplorerReadContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_four_read_operations_require_authentication(): void
    {
        foreach ([
            '/api/v1/institution/tree-directory',
            '/api/v1/institution/tree-directory/example-node',
            '/api/v1/institution/tree-directory/example-node/children',
            '/api/v1/institution/tree-directory/example-node/documents',
        ] as $uri) {
            $this->getJson($uri)
                ->assertUnauthorized()
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ]);
        }
    }

    public function test_virtual_root_returns_all_active_top_level_nodes_in_deterministic_order(): void
    {
        [$institution, $user] = $this->institutionUser();
        $otherInstitution = Institution::factory()->create();

        $second = $this->node($institution, 'Beta', '3');
        $firstById = $this->node($institution, 'Alpha B', '2', id: '10000000-0000-0000-0000-000000000002');
        $first = $this->node($institution, 'Alpha', '1', id: '10000000-0000-0000-0000-000000000001');
        $this->node($institution, 'Inactive root', '4', active: false);
        $this->node($otherInstitution, 'Other tenant', '0');
        $this->node($institution, 'Child', '0', parent: $first, depth: 1);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/tree-directory')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tree directory retrieved successfully.')
            ->assertJsonCount(3, 'data');

        $this->assertSame(
            [$first->id, $firstById->id, $second->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
        $this->assertSame([null, null, null], collect($response->json('data'))->pluck('parent_id')->all());
    }

    public function test_node_detail_preserves_the_current_complete_shape_and_observable_types(): void
    {
        [$institution, $user] = $this->institutionUser();
        $root = $this->node($institution, 'Root', '01');
        $this->node($institution, 'Child', '1', parent: $root, depth: 1);

        Sanctum::actingAs($user);

        $data = $this->getJson("/api/v1/institution/tree-directory/{$root->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tree directory node retrieved successfully.')
            ->json('data');

        $this->assertSame([
            'active',
            'created_at',
            'depth',
            'has_children',
            'id',
            'institution_id',
            'name',
            'order',
            'parent_id',
            'path',
            'updated_at',
        ], $this->sortedKeys($data));
        $this->assertIsInt($data['active']);
        $this->assertSame(1, $data['active']);
        $this->assertIsInt($data['depth']);
        $this->assertSame(0, $data['depth']);
        $this->assertIsInt($data['order']);
        $this->assertSame(1, $data['order']);
        $this->assertIsBool($data['has_children']);
        $this->assertTrue($data['has_children']);
        $this->assertNull($data['parent_id']);
        $this->assertIsString($data['created_at']);
        $this->assertIsString($data['updated_at']);
    }

    public function test_children_returns_only_active_direct_children_from_the_authenticated_institution(): void
    {
        [$institution, $user] = $this->institutionUser();
        $otherInstitution = Institution::factory()->create();
        $parent = $this->node($institution, 'Parent', '1');
        $second = $this->node($institution, 'Beta', '2', parent: $parent, depth: 1);
        $first = $this->node($institution, 'Alpha', '1', parent: $parent, depth: 1);
        $this->node($institution, 'Inactive', '3', parent: $parent, depth: 1, active: false);
        $grandchild = $this->node($institution, 'Grandchild', '0', parent: $first, depth: 2);
        $this->node($otherInstitution, 'Other tenant', '0');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/institution/tree-directory/{$parent->id}/children")
            ->assertOk()
            ->assertJsonPath('message', 'Tree directory children retrieved successfully.')
            ->assertJsonCount(2, 'data');

        $this->assertSame([$first->id, $second->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($grandchild->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertTrue($response->json('data.0.has_children'));
    }

    public function test_documents_returns_only_active_documents_directly_associated_with_the_selected_node(): void
    {
        [$institution, $user] = $this->institutionUser();
        $otherInstitution = Institution::factory()->create();
        $node = $this->node($institution, 'Selected', '1');
        $child = $this->node($institution, 'Child', '1', parent: $node, depth: 1);
        $otherNode = $this->node($otherInstitution, 'Other tenant node', '1');

        $older = $this->document($institution, $node, 'Older', createdAt: '2026-01-01 00:00:00');
        $newerById = $this->document(
            $institution,
            $node,
            'Newer B',
            id: '20000000-0000-0000-0000-000000000002',
            createdAt: '2026-02-01 00:00:00',
        );
        $newer = $this->document(
            $institution,
            $node,
            'Newer A',
            id: '20000000-0000-0000-0000-000000000001',
            createdAt: '2026-02-01 00:00:00',
        );
        $this->document($institution, $node, 'Inactive', status: false);
        $this->document($institution, $child, 'Descendant document');
        $this->document($otherInstitution, $otherNode, 'Other tenant document');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/institution/tree-directory/{$node->id}/documents")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Documents retrieved successfully.')
            ->assertJsonCount(3, 'data');

        $this->assertSame(
            [$newer->id, $newerById->id, $older->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
        $this->assertSame([$node->id, $node->id, $node->id], collect($response->json('data'))->pluck('node_id')->all());

        $document = $response->json('data.0');
        $this->assertSame([
            'author_id',
            'category',
            'created_at',
            'description',
            'id',
            'institution_id',
            'lifecycle',
            'name',
            'node_id',
            'responsible_unit',
            'status',
            'updated_at',
        ], $this->sortedKeys($document));
        $this->assertIsBool($document['status']);
        $this->assertTrue($document['status']);
        $this->assertNull($document['author_id']);
        $this->assertIsString($document['created_at']);
        $this->assertIsString($document['updated_at']);
        $this->assertSame([
            'capabilities',
            'has_current_version',
            'version_count',
        ], $this->sortedKeys($document['lifecycle']));
        $this->assertSame([
            'can_download',
            'can_upload_version',
        ], $this->sortedKeys($document['lifecycle']['capabilities']));
    }

    public function test_document_lifecycle_summary_is_authoritative_for_persisted_versions_and_role(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $editor] = $this->institutionUser();
        $editor->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Editor]));
        $node = $this->node($institution, 'Selected', '1');
        $empty = $this->document($institution, $node, 'Empty');
        $available = $this->document($institution, $node, 'Available');
        $missing = $this->document($institution, $node, 'Missing');
        $unusable = $this->document($institution, $node, 'Unusable');
        $this->version($available, $editor, 1, true, true, 'private/available.pdf');
        $this->version($available, $editor, 2, true, false, 'private/history.pdf');
        $this->version($available, $editor, 3, false, false, 'private/inactive.pdf');
        $this->version($missing, $editor, 1, true, true, 'private/missing.pdf');
        $this->version($unusable, $editor, 1, true, true, '   ');
        Sanctum::actingAs($editor);

        Storage::shouldReceive('disk')->never();

        $response = $this->getJson("/api/v1/institution/tree-directory/{$node->id}/documents")
            ->assertOk()
            ->assertJsonMissingPath('data.0.lifecycle.current_version');
        $this->assertStringNotContainsString('private/', $response->getContent());
        $documents = collect($response->json('data'))
            ->keyBy('id');

        $this->assertSame([
            'version_count' => 0,
            'has_current_version' => false,
            'capabilities' => ['can_download' => false, 'can_upload_version' => true],
        ], $documents[$empty->id]['lifecycle']);
        $this->assertSame([
            'version_count' => 2,
            'has_current_version' => true,
            'capabilities' => ['can_download' => true, 'can_upload_version' => true],
        ], $documents[$available->id]['lifecycle']);
        $this->assertSame([
            'version_count' => 1,
            'has_current_version' => true,
            'capabilities' => ['can_download' => true, 'can_upload_version' => true],
        ], $documents[$missing->id]['lifecycle']);
        $this->assertSame([
            'version_count' => 1,
            'has_current_version' => true,
            'capabilities' => ['can_download' => false, 'can_upload_version' => true],
        ], $documents[$unusable->id]['lifecycle']);

        $reader = User::factory()->for($institution)->create();
        $reader->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Reader]));
        Sanctum::actingAs($reader);
        $this->getJson("/api/v1/institution/tree-directory/{$node->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.0.lifecycle.capabilities.can_upload_version', false);
    }

    public function test_document_lifecycle_summary_uses_a_constant_number_of_version_queries(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $user] = $this->institutionUser();
        Sanctum::actingAs($user);
        Storage::shouldReceive('disk')->never();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'document_versions')) {
                $queries[] = $query->sql;
            }
        });

        foreach ([1, 6, 12] as $documentCount) {
            $node = $this->node($institution, "Selected {$documentCount}", (string) $documentCount);
            foreach (range(1, $documentCount) as $number) {
                $document = $this->document($institution, $node, "Document {$documentCount}-{$number}");
                $this->version($document, $user, 1, true, true, "private/{$documentCount}-{$number}.pdf");
            }

            $queries = [];
            $this->getJson("/api/v1/institution/tree-directory/{$node->id}/documents")
                ->assertOk()
                ->assertJsonCount($documentCount, 'data');

            $this->assertCount(2, $queries);
        }
    }

    public function test_top_level_nodes_are_regular_selectable_nodes_with_children_and_documents(): void
    {
        [$institution, $user] = $this->institutionUser();
        $firstRoot = $this->node($institution, 'First root', '1');
        $secondRoot = $this->node($institution, 'Second root', '2');
        $child = $this->node($institution, 'Child', '1', parent: $secondRoot, depth: 1);
        $document = $this->document($institution, $secondRoot, 'Direct document');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/institution/tree-directory')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $firstRoot->id])
            ->assertJsonFragment(['id' => $secondRoot->id])
            ->assertJsonMissing(['id' => $document->id]);

        $this->getJson("/api/v1/institution/tree-directory/{$secondRoot->id}")
            ->assertOk()
            ->assertJsonPath('data.depth', 0);

        $this->getJson("/api/v1/institution/tree-directory/{$secondRoot->id}/children")
            ->assertOk()
            ->assertJsonPath('data.0.id', $child->id);

        $this->getJson("/api/v1/institution/tree-directory/{$secondRoot->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.0.id', $document->id);
    }

    public function test_inactive_cross_tenant_and_missing_nodes_are_indistinguishable(): void
    {
        [$institution, $user] = $this->institutionUser();
        $otherInstitution = Institution::factory()->create();
        $inactive = $this->node($institution, 'Inactive', '1', active: false);
        $foreign = $this->node($otherInstitution, 'Foreign', '1');

        Sanctum::actingAs($user);

        foreach ([$inactive->id, $foreign->id, 'missing-node'] as $nodeId) {
            foreach ([
                "/api/v1/institution/tree-directory/{$nodeId}",
                "/api/v1/institution/tree-directory/{$nodeId}/children",
                "/api/v1/institution/tree-directory/{$nodeId}/documents",
            ] as $uri) {
                $this->getJson($uri)
                    ->assertNotFound()
                    ->assertExactJson([
                        'success' => false,
                        'message' => 'Node not found.',
                    ]);
            }
        }
    }

    /** @return array{Institution, User} */
    private function institutionUser(): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();

        return [$institution, $user];
    }

    private function node(
        Institution $institution,
        string $name,
        string $order,
        ?Node $parent = null,
        int $depth = 0,
        bool $active = true,
        ?string $id = null,
    ): Node {
        $node = new Node;
        if ($id !== null) {
            $node->id = $id;
        } else {
            $node->id = $node->newUniqueId();
        }
        $node->fill([
            'name' => $name,
            'path' => $parent ? "{$parent->path}/{$node->id}" : $node->id,
            'depth' => $depth,
            'order' => $order,
            'active' => $active,
            'institution_id' => $institution->id,
            'parent_id' => $parent?->id,
        ]);
        $node->save();

        return $node;
    }

    private function document(
        Institution $institution,
        Node $node,
        string $name,
        bool $status = true,
        ?string $id = null,
        ?string $createdAt = null,
    ): Document {
        $document = new Document;
        if ($id !== null) {
            $document->id = $id;
        }
        $document->fill([
            'name' => $name,
            'description' => "{$name} description",
            'category' => 'Category',
            'responsible_unit' => 'Unit',
            'status' => $status,
            'author_id' => null,
            'institution_id' => $institution->id,
            'node_id' => $node->id,
        ]);
        if ($createdAt !== null) {
            $document->created_at = $createdAt;
            $document->updated_at = $createdAt;
        }
        $document->save();

        return $document;
    }

    private function version(
        Document $document,
        User $author,
        int $number,
        bool $active,
        bool $current,
        string $path,
    ): DocumentVersion {
        return DocumentVersion::create([
            'version_number' => $number,
            'url' => $path,
            'filename' => basename($path),
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'author_id' => $author->id,
            'document_id' => $document->id,
            'institution_id' => $document->institution_id,
            'node_id' => $document->node_id,
            'active' => $active,
            'is_current' => $current,
        ]);
    }

    /** @return list<string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }
}
