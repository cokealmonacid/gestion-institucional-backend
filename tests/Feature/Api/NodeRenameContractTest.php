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

class NodeRenameContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_renames_only_the_name_and_case_only_changes_are_real(): void
    {
        [$institution, $admin] = $this->actor(RoleType::Admin);
        $parent = $this->node($institution, 'Parent');
        $node = $this->node($institution, 'Policy', $parent);
        $child = $this->node($institution, 'Child', $node);
        $document = Document::create(['name' => 'Doc', 'status' => true, 'institution_id' => $institution->id, 'node_id' => $node->id]);
        $preserved = ['id', 'institution_id', 'parent_id', 'parent_scope', 'path', 'depth', 'order'];
        $before = $node->only($preserved);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => 'policy'])
            ->assertOk()->assertJsonPath('data.name', 'policy')
            ->assertJsonPath('message', 'Tree directory node renamed successfully.');

        $node->refresh();
        $this->assertSame($before, $node->only($preserved));
        $this->assertTrue((bool) $node->active);
        $this->assertSame($node->id, $child->fresh()->parent_id);
        $this->assertSame($node->id, $document->fresh()->node_id);
    }

    public function test_no_op_has_no_write_and_permissions_are_preserved(): void
    {
        [$institution, $admin] = $this->actor(RoleType::Admin);
        $node = $this->node($institution, 'Área');
        $updatedAt = $node->updated_at;
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => " A\u{0301}rea "])->assertOk();
        $this->assertTrue($node->fresh()->updated_at->equalTo($updatedAt));

        foreach ([RoleType::Editor, RoleType::Reader] as $role) {
            [, $actor] = $this->actor($role, $institution);
            Sanctum::actingAs($actor);
            $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => 'Denied'])
                ->assertForbidden()->assertJsonPath('error.code', 'NODE_RENAME_FORBIDDEN');
        }
    }

    public function test_it_rejects_invalid_extra_duplicate_foreign_and_inaccessible_nodes(): void
    {
        [$institution, $admin] = $this->actor(RoleType::Admin);
        $parent = $this->node($institution, 'Parent');
        $node = $this->node($institution, 'Node', $parent);
        $this->node($institution, 'DUPLICATE', $parent, false);
        Sanctum::actingAs($admin);

        foreach ([' ', 'a/b', 'a\\b', "a\nb", str_repeat('x', 256)] as $name) {
            $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => $name])
                ->assertUnprocessable()->assertJsonPath('error.code', 'NODE_NAME_INVALID');
        }
        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => 'Valid', 'path' => 'x'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => 'duplicate'])
            ->assertConflict()->assertJsonPath('error.code', 'NODE_NAME_DUPLICATE');

        $parent->update(['active' => false]);
        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => 'Blocked'])
            ->assertNotFound()->assertJsonPath('error.code', 'NODE_NOT_AVAILABLE');
        $parent->update(['active' => true]);
        $node->update(['active' => false]);
        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/name", ['name' => 'Blocked'])
            ->assertNotFound()->assertJsonPath('error.code', 'NODE_NOT_AVAILABLE');

        [$other] = $this->actor(RoleType::Admin);
        $foreign = $this->node($other, 'Foreign');
        $this->patchJson("/api/v1/institution/tree-directory/{$foreign->id}/name", ['name' => 'Hidden'])
            ->assertNotFound()->assertJsonPath('error.code', 'NODE_NOT_AVAILABLE');
    }

    private function actor(RoleType $role, ?Institution $institution = null): array
    {
        $institution ??= Institution::factory()->create();
        $actor = User::factory()->for($institution)->create();
        $rol = Rol::firstOrCreate(['type' => $role]);
        $actor->roles()->attach($rol->id);

        return [$institution, $actor];
    }

    private function node(Institution $institution, string $name, ?Node $parent = null, bool $active = true): Node
    {
        return Node::factory()->for($institution)->create([
            'name' => $name,
            'parent_id' => $parent?->id,
            'active' => $active,
        ]);
    }
}
