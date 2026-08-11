<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentExplorerCreateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_node_in_the_virtual_root(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/institution/tree-directory', [
            'name' => '  Contratos  ',
            'parent_id' => null,
        ])->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Contratos')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.depth', 0)
            ->assertJsonPath('data.order', 1)
            ->assertJsonPath('data.has_children', false);

        $id = $response->json('data.id');
        $this->assertSame($id, $response->json('data.path'));
        $this->assertIsInt($response->json('data.order'));
        $this->assertDatabaseHas('nodes', [
            'id' => $id,
            'institution_id' => $institution->id,
            'parent_id' => null,
            'name' => 'Contratos',
            'parent_scope' => 'R',
            'order' => 1,
        ]);
    }

    public function test_editor_creates_a_child_with_an_id_materialized_path_and_next_sibling_order(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Editor);
        $parent = $this->node($institution, 'Root', null, 1);
        $first = $this->node($institution, 'First', $parent, 1);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/institution/tree-directory', [
            'name' => 'Child',
            'parent_id' => $parent->id,
        ])->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.depth', 1)
            ->assertJsonPath('data.order', 2);

        $this->assertSame($parent->path.'/'.$response->json('data.id'), $response->json('data.path'));
        $this->assertNotSame($first->id, $response->json('data.id'));
    }

    public function test_distinct_root_names_and_same_name_under_different_parents_are_allowed(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $firstParent = $this->node($institution, 'First parent', null, 1);
        $secondParent = $this->node($institution, 'Second parent', null, 2);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'Root A', 'parent_id' => null])->assertCreated();
        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'Root B', 'parent_id' => null])->assertCreated();
        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'Shared', 'parent_id' => $firstParent->id])->assertCreated();
        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'shared', 'parent_id' => $secondParent->id])->assertCreated();
    }

    public function test_case_nfc_and_inactive_siblings_reserve_their_names(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $this->node($institution, 'ÁREA', null, 1, false);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/institution/tree-directory', [
            'name' => "A\u{0301}rea",
            'parent_id' => null,
        ])->assertConflict()->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'NODE_NAME_DUPLICATE',
                'message' => 'A node with this name already exists in the selected location.',
                'fields' => ['name' => ['A node with this name already exists in the selected location.']],
            ],
        ]);
    }

    public function test_duplicate_name_is_scoped_to_the_same_parent_and_protected_by_the_database(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Admin);
        $parent = $this->node($institution, 'Parent', null, 1);
        $this->node($institution, 'Duplicate', $parent, 1);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/institution/tree-directory', [
            'name' => 'duplicate',
            'parent_id' => $parent->id,
        ])->assertConflict()->assertJsonPath('error.code', 'NODE_NAME_DUPLICATE');

        $this->assertSame(1, Node::query()->where('parent_id', $parent->id)->count());

        $this->expectException(QueryException::class);
        DB::table('nodes')->insert([
            'id' => '90000000-0000-0000-0000-000000000001',
            'name' => 'DUPLICATE',
            'normalized_name' => 'duplicate',
            'name_fingerprint' => hash('sha256', 'duplicate'),
            'parent_scope' => 'P:'.$parent->id,
            'path' => $parent->path.'/90000000-0000-0000-0000-000000000001',
            'depth' => 1,
            'order' => 2,
            'active' => true,
            'institution_id' => $institution->id,
            'parent_id' => $parent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_request_validation_rejects_missing_parent_derived_fields_and_invalid_names(): void
    {
        [, $user] = $this->institutionUser(RoleType::Admin);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'Valid'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        foreach (['   ', 'A/B', 'A\\B', "A\nB", str_repeat('x', 256)] as $name) {
            $this->postJson('/api/v1/institution/tree-directory', ['name' => $name, 'parent_id' => null])
                ->assertUnprocessable()->assertJsonPath('error.code', 'NODE_NAME_INVALID');
        }

        $this->postJson('/api/v1/institution/tree-directory', [
            'name' => 'Valid',
            'parent_id' => null,
            'order' => 4,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_authentication_authorization_and_tenant_isolation_are_enforced(): void
    {
        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'A', 'parent_id' => null])
            ->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');

        [, $reader] = $this->institutionUser(RoleType::Reader);
        Sanctum::actingAs($reader);
        $this->postJson('/api/v1/institution/tree-directory', ['name' => 'A', 'parent_id' => null])
            ->assertForbidden()->assertJsonPath('error.code', 'NODE_CREATE_FORBIDDEN');

        $readerInstitution = $reader->institution;
        $readerParent = $this->node($readerInstitution, 'Reader parent', null, 1);
        $this->postJson("/api/v1/institution/tree-directory/{$readerParent->id}", ['name' => 'A'])
            ->assertForbidden()->assertExactJson(['success' => false, 'message' => 'Forbidden.']);

        [$otherInstitution] = $this->institutionUser(RoleType::Admin);
        $foreignParent = $this->node($otherInstitution, 'Foreign', null, 1);
        [$institution, $admin] = $this->institutionUser(RoleType::Admin);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/institution/tree-directory', [
            'name' => 'Hidden',
            'parent_id' => $foreignParent->id,
        ])->assertNotFound()->assertJsonPath('error.code', 'NODE_PARENT_NOT_FOUND');

        $documentNode = $this->node($institution, 'Documents', null, 1);
        $document = Document::create([
            'name' => 'File', 'description' => null, 'category' => null, 'responsible_unit' => null,
            'status' => true, 'author_id' => null, 'institution_id' => $institution->id, 'node_id' => $documentNode->id,
        ]);
        $this->postJson('/api/v1/institution/tree-directory', [
            'name' => 'Invalid parent',
            'parent_id' => $document->id,
        ])->assertNotFound()->assertJsonPath('error.code', 'NODE_PARENT_NOT_FOUND');
    }

    public function test_legacy_post_keeps_its_200_success_shape_but_uses_server_derived_values(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Editor);
        $parent = $this->node($institution, 'Parent', null, 1);
        Sanctum::actingAs($user);

        $data = $this->postJson("/api/v1/institution/tree-directory/{$parent->id}", [
            'name' => ' Legacy ',
            'path' => 'client/path',
            'order' => '999',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tree directory node created successfully.')
            ->assertJsonMissingPath('data.has_children')
            ->json('data');

        $this->assertSame('Legacy', $data['name']);
        $this->assertSame($parent->path.'/'.$data['id'], $data['path']);
        $this->assertSame(1, $data['order']);
        $this->assertArrayNotHasKey('normalized_name', $data);
        $this->assertArrayNotHasKey('parent_scope', $data);
    }

    public function test_order_constraint_prevents_two_positions_in_the_same_scope(): void
    {
        [$institution] = $this->institutionUser(RoleType::Admin);
        $this->node($institution, 'First', null, 1);

        $this->expectException(QueryException::class);
        $this->node($institution, 'Second', null, 1);
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

    private function node(Institution $institution, string $name, ?Node $parent, int $order, bool $active = true): Node
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
}
