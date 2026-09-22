<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Models\DocumentVersionCommentHistory;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentVersionNotesContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_can_read_notes_and_history_without_actor_email(): void
    {
        foreach (RoleType::cases() as $role) {
            [$institution, $actor, $document, $version] = $this->context($role);
            $history = $this->history($document, $version, $actor, null, 'Nota');
            $version->update(['comment' => 'Nota']);
            Sanctum::actingAs($actor);

            $this->getJson("/api/v1/documents/{$document->id}/versions/notes")
                ->assertOk()
                ->assertJsonPath('data.0.note', 'Nota')
                ->assertJsonPath('data.0.actor.name', $actor->name)
                ->assertJsonMissingPath('data.0.actor.email');
            $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note/history")
                ->assertOk()
                ->assertJsonPath('data.0.id', $history->id)
                ->assertJsonPath('data.0.actor.name', $actor->name)
                ->assertJsonMissingPath('data.0.actor.email');
        }
    }

    public function test_admin_and_editor_can_create_replace_and_clear_a_note_but_reader_cannot(): void
    {
        foreach ([RoleType::Admin, RoleType::Editor] as $role) {
            [, $actor, $document, $version] = $this->context($role);
            Sanctum::actingAs($actor);

            $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note", [
                'note' => '  Primera nota  ',
            ])->assertOk()->assertJsonPath('data.note.note', 'Primera nota');
            $this->assertDatabaseHas('document_version_comment_histories', [
                'document_version_id' => $version->id,
                'previous_comment' => null,
                'new_comment' => 'Primera nota',
                'actor_name' => $actor->name,
            ]);

            $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note", [
                'note' => 'Segunda nota',
            ])->assertOk()->assertJsonPath('data.transition.previous_note', 'Primera nota')
                ->assertJsonPath('data.transition.new_note', 'Segunda nota');

            $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note", [
                'note' => null,
            ])->assertOk()->assertJsonPath('data.note.note', null)
                ->assertJsonPath('data.transition.previous_note', 'Segunda nota')
                ->assertJsonPath('data.transition.new_note', null);

            $this->assertSame(3, DocumentVersionCommentHistory::where('document_version_id', $version->id)->count());
        }

        [, $reader, $document, $version] = $this->context(RoleType::Reader);
        Sanctum::actingAs($reader);
        $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note", [
            'note' => 'Prohibida',
        ])->assertForbidden();
        $this->assertNull($version->fresh()->comment);
    }

    public function test_note_validation_normalizes_text_and_rejects_invalid_or_unchanged_values(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Editor);
        Sanctum::actingAs($actor);
        $uri = "/api/v1/documents/{$document->id}/versions/{$version->id}/note";

        $this->patchJson($uri, [])->assertUnprocessable();
        $this->patchJson($uri, ['note' => ''])->assertUnprocessable();
        $this->patchJson($uri, ['note' => '   '])->assertUnprocessable();
        $this->patchJson($uri, ['note' => str_repeat('a', 2001)])->assertUnprocessable();

        $this->patchJson($uri, ['note' => '  '.str_repeat('a', 2000).'  '])->assertOk();
        $historyCount = DocumentVersionCommentHistory::count();
        $this->patchJson($uri, ['note' => str_repeat('a', 2000)])
            ->assertConflict()
            ->assertJsonPath('error.code', 'DOCUMENT_VERSION_NOTE_UNCHANGED');
        $this->assertSame($historyCount, DocumentVersionCommentHistory::count());
    }

    public function test_canonical_mutation_rejects_the_retired_comment_field(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Editor);
        Sanctum::actingAs($actor);

        $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note", [
            'comment' => 'Campo retirado',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertNull($version->fresh()->comment);
    }

    public function test_legacy_comment_routes_are_not_available(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Editor);
        Sanctum::actingAs($actor);

        $this->getJson("/api/v1/documents/{$document->id}/versions/comments")->assertNotFound();
        $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/comments")->assertNotFound();
        $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/comment", [
            'comment' => 'Campo retirado',
        ])->assertNotFound();
    }

    public function test_tenant_and_document_version_pairing_are_enforced_for_reads_and_mutation(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Editor);
        [, , $foreignDocument, $foreignVersion] = $this->context(RoleType::Editor);
        $otherDocument = $this->document($document->institution_id, $actor);
        Sanctum::actingAs($actor);

        $this->getJson("/api/v1/documents/{$foreignDocument->id}/versions/notes")->assertNotFound();
        $this->getJson("/api/v1/documents/{$foreignDocument->id}/versions/{$foreignVersion->id}/note/history")
            ->assertNotFound();
        $this->patchJson("/api/v1/documents/{$foreignDocument->id}/versions/{$foreignVersion->id}/note", [
            'note' => 'Ajena',
        ])->assertNotFound();

        $this->getJson("/api/v1/documents/{$otherDocument->id}/versions/{$version->id}/note/history")
            ->assertNotFound();
        $this->patchJson("/api/v1/documents/{$otherDocument->id}/versions/{$version->id}/note", [
            'note' => 'Documento incorrecto',
        ])->assertNotFound();
    }

    public function test_inactive_documents_and_versions_remain_readable_but_are_read_only(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Editor);
        $this->history($document, $version, $actor, null, 'Histórica');
        $version->update(['comment' => 'Histórica', 'active' => false, 'is_current' => false]);
        $document->update(['status' => false]);
        Sanctum::actingAs($actor);

        $this->getJson("/api/v1/documents/{$document->id}/versions/notes")
            ->assertOk()->assertJsonPath('data.0.note', 'Histórica');
        $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note/history")
            ->assertOk()->assertJsonPath('data.0.new_note', 'Histórica');
        $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note", [
            'note' => 'Cambio',
        ])->assertConflict()->assertJsonPath('error.code', 'DOCUMENT_VERSION_NOTE_READ_ONLY');
    }

    public function test_history_is_cursor_paginated_and_deterministically_ordered(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Reader);
        Carbon::setTestNow('2026-09-22 12:00:00');
        $first = $this->history($document, $version, $actor, null, 'Uno');
        $second = $this->history($document, $version, $actor, 'Uno', 'Dos');
        Carbon::setTestNow();
        Sanctum::actingAs($actor);

        $page = $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note/history?limit=1")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(max($first->id, $second->id), $page->json('data.0.id'));
        $cursor = $page->json('meta.next_cursor');
        $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note/history?limit=1&cursor=".urlencode($cursor))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_latest_note_change_uses_the_greatest_id_when_timestamps_are_equal(): void
    {
        [$institution, $actor, $document, $version] = $this->context(RoleType::Reader);
        $otherActor = $this->user($institution, RoleType::Reader);
        Carbon::setTestNow('2026-09-22 12:00:00');
        $first = $this->history($document, $version, $actor, null, 'Uno');
        $second = $this->history($document, $version, $otherActor, 'Uno', 'Dos');
        Carbon::setTestNow();
        $expectedActor = $first->id > $second->id ? $actor : $otherActor;
        Sanctum::actingAs($actor);

        $this->getJson("/api/v1/documents/{$document->id}/versions/notes")
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', $expectedActor->id);
    }

    public function test_soft_deleted_actor_keeps_a_readable_snapshot(): void
    {
        [$institution, $actor, $document, $version] = $this->context(RoleType::Editor);
        $history = $this->history($document, $version, $actor, null, 'Nota');
        $actor->delete();
        $reader = $this->user($institution, RoleType::Reader);
        Sanctum::actingAs($reader);

        $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/note/history")
            ->assertOk()
            ->assertJsonPath('data.0.id', $history->id)
            ->assertJsonPath('data.0.actor.name', $actor->name)
            ->assertJsonPath('data.0.actor.active', false)
            ->assertJsonMissingPath('data.0.actor.email');
    }

    public function test_note_changes_create_content_free_document_events(): void
    {
        [, $actor, $document, $version] = $this->context(RoleType::Editor);
        Sanctum::actingAs($actor);
        $uri = "/api/v1/documents/{$document->id}/versions/{$version->id}/note";

        $this->patchJson($uri, ['note' => 'Contenido reservado'])->assertOk();
        $updated = DocumentEvent::where('type', DocumentEventType::VersionNoteUpdated->value)->firstOrFail();
        $this->assertSame($version->id, $updated->version_id);
        $this->assertStringNotContainsString('Contenido reservado', json_encode($updated->detail));

        $this->patchJson($uri, ['note' => null])->assertOk();
        $cleared = DocumentEvent::where('type', DocumentEventType::VersionNoteCleared->value)->firstOrFail();
        $this->assertStringNotContainsString('Contenido reservado', json_encode($cleared->detail));

        $history = $this->getJson("/api/v1/documents/{$document->id}/history")->assertOk();
        $encoded = json_encode($history->json('data'), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(DocumentEventType::VersionNoteUpdated->value, $encoded);
        $this->assertStringContainsString(DocumentEventType::VersionNoteCleared->value, $encoded);
        $this->assertStringNotContainsString('Contenido reservado', $encoded);
    }

    private function context(RoleType $role): array
    {
        $institution = Institution::factory()->create();
        $actor = $this->user($institution, $role);
        $document = $this->document($institution->id, $actor);
        $version = $this->version($document, $actor);

        return [$institution, $actor, $document, $version];
    }

    private function user(Institution $institution, RoleType $role): User
    {
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::firstOrCreate(['type' => $role]));

        return $user;
    }

    private function document(string $institutionId, User $author): Document
    {
        $node = Node::factory()->create(['institution_id' => $institutionId, 'parent_id' => null, 'active' => true]);

        return Document::create([
            'name' => 'Policy', 'status' => true, 'author_id' => $author->id,
            'institution_id' => $institutionId, 'node_id' => $node->id,
        ]);
    }

    private function version(Document $document, User $author): DocumentVersion
    {
        return DocumentVersion::create([
            'version_number' => 1, 'url' => 'private/file.pdf', 'filename' => 'policy.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 3, 'author_id' => $author->id,
            'document_id' => $document->id, 'institution_id' => $document->institution_id,
            'node_id' => $document->node_id, 'active' => true, 'is_current' => true,
        ]);
    }

    private function history(
        Document $document,
        DocumentVersion $version,
        User $actor,
        ?string $previous,
        ?string $new,
    ): DocumentVersionCommentHistory {
        return DocumentVersionCommentHistory::create([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'previous_comment' => $previous,
            'new_comment' => $new,
        ]);
    }
}
