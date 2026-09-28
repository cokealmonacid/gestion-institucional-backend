<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentRenameContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_editor_rename_with_an_event_and_preserve_document_data(): void
    {
        foreach ([RoleType::Admin, RoleType::Editor] as $role) {
            [$institution, $actor, $node, $document] = $this->context($role);
            $version = DocumentVersion::create([
                'version_number' => 1, 'filename' => 'original.pdf', 'url' => 'private/original.pdf',
                'mime_type' => 'application/pdf', 'file_size' => 10, 'active' => true, 'is_current' => true,
                'author_id' => $actor->id, 'institution_id' => $institution->id, 'document_id' => $document->id,
            ]);
            $before = $document->only(['id', 'institution_id', 'node_id', 'status', 'author_id', 'description', 'category', 'responsible_unit']);
            Sanctum::actingAs($actor);

            $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => ' policy '])
                ->assertOk()->assertJsonPath('data.name', 'policy')
                ->assertJsonPath('data.node_id', $node->id)
                ->assertJsonPath('message', 'Document renamed successfully.');

            $this->assertSame($before, $document->fresh()->only(array_keys($before)));
            $this->assertDatabaseHas('document_versions', [
                'id' => $version->id, 'filename' => 'original.pdf', 'url' => 'private/original.pdf',
            ]);
            $event = DocumentEvent::where('document_id', $document->id)->sole();
            $this->assertSame('document.renamed', $event->type->value);
            $this->assertSame(['previous_name' => 'Policy', 'new_name' => 'policy'], $event->detail);
            $this->assertSame($actor->id, $event->actor_user_id);
            $this->assertSame($actor->name, $event->actor_name);

            $historyEvent = collect($this->getJson("/api/v1/documents/{$document->id}/history")
                ->assertOk()->json('data'))->firstWhere('type', 'document.renamed');
            $this->assertSame(['previous_name' => 'Policy', 'new_name' => 'policy'], $historyEvent['detail']);
            $this->assertSame(['id', 'name', 'active'], array_keys($historyEvent['actor']));
            $this->assertArrayNotHasKey('email', $historyEvent['actor']);
        }
    }

    public function test_no_op_writes_nothing_and_case_only_is_a_real_change(): void
    {
        [, $actor, , $document] = $this->context(RoleType::Admin, 'Póliza');
        $updatedAt = $document->updated_at;
        Sanctum::actingAs($actor);

        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => " Po\u{0301}liza "])->assertOk();
        $this->assertTrue($document->fresh()->updated_at->equalTo($updatedAt));
        $this->assertDatabaseCount('document_events', 0);

        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'PÓLIZA'])->assertOk();
        $this->assertSame('PÓLIZA', $document->fresh()->name);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'type' => 'document.renamed']);
    }

    public function test_permissions_isolation_validation_duplicates_and_inactive_paths(): void
    {
        [$institution, $admin, $node, $document] = $this->context(RoleType::Admin);
        Document::create(['name' => 'Same', 'status' => true, 'institution_id' => $institution->id, 'node_id' => $node->id]);
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Same'])->assertOk();

        foreach ([' ', 'a/b', 'a\\b', "a\nb", str_repeat('x', 256)] as $name) {
            $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => $name])
                ->assertUnprocessable()->assertJsonPath('error.code', 'DOCUMENT_NAME_INVALID');
        }
        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Valid', 'description' => 'x'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        [, $reader] = $this->actor(RoleType::Reader, $institution);
        Sanctum::actingAs($reader);
        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Denied'])
            ->assertForbidden()->assertJsonPath('error.code', 'DOCUMENT_RENAME_FORBIDDEN');

        Sanctum::actingAs($admin);
        $node->update(['active' => false]);
        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Blocked'])
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
        $node->update(['active' => true]);
        $document->update(['status' => false]);
        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Blocked'])
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');

        [$foreignInstitution] = $this->actor(RoleType::Admin);
        $foreignNode = Node::factory()->for($foreignInstitution)->create(['active' => true]);
        $foreign = Document::create(['name' => 'Foreign', 'status' => true, 'institution_id' => $foreignInstitution->id, 'node_id' => $foreignNode->id]);
        $this->patchJson("/api/v1/documents/{$foreign->id}/name", ['name' => 'Hidden'])
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
    }

    public function test_inactive_ancestor_blocks_even_an_equivalent_name(): void
    {
        [$institution, $actor] = $this->actor(RoleType::Admin);
        $parent = Node::factory()->for($institution)->create(['name' => 'Parent', 'active' => false]);
        $node = Node::factory()->for($institution)->create(['name' => 'Child', 'parent_id' => $parent->id, 'active' => true]);
        $document = Document::create(['name' => 'Policy', 'status' => true, 'institution_id' => $institution->id, 'node_id' => $node->id]);
        Sanctum::actingAs($actor);

        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Policy'])
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
    }

    public function test_legacy_name_uses_the_same_rules_and_mixed_updates_are_atomic(): void
    {
        [, $actor, $node, $document] = $this->context(RoleType::Admin);
        Sanctum::actingAs($actor);

        $this->patchJson("/api/v1/documents/{$document->id}", ['description' => 'Metadata only'])
            ->assertOk();
        $this->assertSame('Metadata only', $document->fresh()->description);
        $this->assertDatabaseCount('document_events', 0);

        $this->patchJson("/api/v1/documents/{$document->id}", ['name' => ' Legacy ', 'category' => 'Changed'])
            ->assertOk();
        $this->assertSame('Legacy', $document->fresh()->name);
        $this->assertSame('Changed', $document->fresh()->category);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'type' => 'document.renamed']);

        $node->update(['active' => false]);
        $this->patchJson("/api/v1/documents/{$document->id}", ['name' => 'Blocked', 'description' => 'Must roll back'])
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
        $this->assertSame('Metadata only', $document->fresh()->description);
    }

    public function test_event_failure_rolls_back_canonical_and_legacy_mixed_updates(): void
    {
        [, $actor, , $document] = $this->context(RoleType::Admin);
        Sanctum::actingAs($actor);
        DB::unprepared("CREATE TRIGGER fail_document_rename BEFORE INSERT ON document_events WHEN NEW.type = 'document.renamed' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->patchJson("/api/v1/documents/{$document->id}/name", ['name' => 'Canonical'])->assertStatus(500);
        $this->assertSame('Policy', $document->fresh()->name);

        $this->patchJson("/api/v1/documents/{$document->id}", ['name' => 'Legacy', 'description' => 'Changed'])->assertStatus(500);
        $document->refresh();
        $this->assertSame('Policy', $document->name);
        $this->assertSame('Original', $document->description);
    }

    private function context(RoleType $role, string $name = 'Policy'): array
    {
        [$institution, $actor] = $this->actor($role);
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $document = Document::create([
            'name' => $name, 'description' => 'Original', 'category' => 'Policy', 'responsible_unit' => 'Legal',
            'status' => true, 'author_id' => $actor->id, 'institution_id' => $institution->id, 'node_id' => $node->id,
        ]);

        return [$institution, $actor, $node, $document];
    }

    private function actor(RoleType $role, ?Institution $institution = null): array
    {
        $institution ??= Institution::factory()->create();
        $actor = User::factory()->for($institution)->create();
        $rol = Rol::firstOrCreate(['type' => $role]);
        $actor->roles()->attach($rol->id);

        return [$institution, $actor];
    }
}
