<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class InstitutionDocumentsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_documents_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/institution/documents')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_index_lists_only_active_documents_from_the_authenticated_institution(): void
    {
        [$institution, $user] = $this->institutionUser();
        $otherInstitution = Institution::factory()->create();

        $node = $this->node($institution, 'Node', '1');
        $otherNode = $this->node($otherInstitution, 'Other tenant node', '1');

        $active = $this->document($institution, $node, 'Active');
        $this->document($institution, $node, 'Inactive', status: false);
        $this->document($otherInstitution, $otherNode, 'Other tenant document');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/documents')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Documents retrieved successfully.')
            ->assertJsonPath('data.total', 1);

        $this->assertSame($active->id, $response->json('data.data.0.id'));
    }

    public function test_status_false_also_includes_inactive_documents(): void
    {
        [$institution, $user] = $this->institutionUser();
        $node = $this->node($institution, 'Node', '1');

        $this->document($institution, $node, 'Active');
        $this->document($institution, $node, 'Inactive', status: false);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/institution/documents')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/v1/institution/documents?status=false')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_documents_are_ordered_by_creation_desc_then_id_and_keep_the_shape(): void
    {
        [$institution, $user] = $this->institutionUser();
        $node = $this->node($institution, 'Node', '1');

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

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/documents')
            ->assertOk()
            ->assertJsonPath('data.total', 3);

        $this->assertSame(
            [$newer->id, $newerById->id, $older->id],
            collect($response->json('data.data'))->pluck('id')->all(),
        );

        $document = $response->json('data.data.0');
        $this->assertSame([
            'author',
            'category',
            'created_at',
            'description',
            'id',
            'lifecycle',
            'name',
            'node_id',
            'responsible_unit',
            'status',
            'updated_at',
        ], $this->sortedKeys($document));
        $this->assertNull($document['author']);
        $this->assertIsString($document['created_at']);
        $this->assertDoesNotMatchRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $document['created_at']);
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

    public function test_document_returns_the_author_name_and_omits_author_id_and_institution_id(): void
    {
        [$institution, $user] = $this->institutionUser();
        $node = $this->node($institution, 'Node', '1');
        $document = $this->document($institution, $node, 'Authored');
        $document->author_id = $user->id;
        $document->save();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/documents')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $data = $response->json('data.data.0');
        $this->assertArrayNotHasKey('author_id', $data);
        $this->assertArrayNotHasKey('institution_id', $data);
        $this->assertSame($user->name, $data['author']);
    }

    public function test_pagination_navigates_through_pages(): void
    {
        [$institution, $user] = $this->institutionUser();
        $node = $this->node($institution, 'Node', '1');
        foreach (range(1, 5) as $number) {
            $this->document($institution, $node, "Document {$number}", createdAt: "2026-01-0{$number} 00:00:00");
        }

        Sanctum::actingAs($user);

        $page = $this->getJson('/api/v1/institution/documents?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.last_page', 3)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.from', 1)
            ->assertJsonPath('data.to', 2)
            ->assertJsonCount(2, 'data.data');

        $this->assertNotNull($page->json('data.next_page_url'));
        $this->assertNull($page->json('data.prev_page_url'));

        $last = $this->getJson('/api/v1/institution/documents?per_page=2&page=3')
            ->assertOk()
            ->assertJsonPath('data.current_page', 3)
            ->assertJsonCount(1, 'data.data');

        $this->assertNull($last->json('data.next_page_url'));
        $this->assertNotNull($last->json('data.prev_page_url'));
    }

    public function test_validation_rejects_an_invalid_per_page(): void
    {
        [$institution, $user] = $this->institutionUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/institution/documents?per_page=0')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed.');
    }

    public function test_lifecycle_summary_capabilities_reflect_the_role(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $editor] = $this->institutionUser(RoleType::Editor);
        $node = $this->node($institution, 'Node', '1');
        $document = $this->document($institution, $node, 'Available');
        $this->version($document, $editor, 1, true, true, 'private/available.pdf');

        Sanctum::actingAs($editor);

        $this->getJson('/api/v1/institution/documents')
            ->assertOk()
            ->assertJsonPath('data.data.0.lifecycle.version_count', 1)
            ->assertJsonPath('data.data.0.lifecycle.has_current_version', true)
            ->assertJsonPath('data.data.0.lifecycle.capabilities.can_download', true)
            ->assertJsonPath('data.data.0.lifecycle.capabilities.can_upload_version', true);

        $reader = User::factory()->for($institution)->create();
        $reader->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Reader]));
        Sanctum::actingAs($reader);

        $this->getJson('/api/v1/institution/documents')
            ->assertOk()
            ->assertJsonPath('data.data.0.lifecycle.capabilities.can_upload_version', false);
    }

    /** @return array{Institution, User} */
    private function institutionUser(RoleType $role = RoleType::Editor): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::firstOrCreate(['type' => $role]));

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
