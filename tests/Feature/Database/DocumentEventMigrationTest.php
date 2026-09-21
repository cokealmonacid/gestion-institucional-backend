<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentResponsibleHistory;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Services\BackfillDocumentEvents;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentEventMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_backfill_is_deterministic_idempotent_and_does_not_invent_promotions(): void
    {
        [$institution, $user, $document] = $this->context();
        $document->timestamps = false;
        $document->created_at = '2026-01-01 10:00:00';
        $document->save();
        $version = DocumentVersion::create([
            'version_number' => 1, 'url' => 'private/path', 'filename' => 'policy.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'active' => true,
            'is_current' => true, 'author_id' => $user->id, 'document_id' => $document->id,
            'institution_id' => $institution->id, 'node_id' => $document->node_id,
        ]);
        $history = DocumentResponsibleHistory::create([
            'document_id' => $document->id, 'new_responsible_user_id' => $user->id,
            'actor_user_id' => $user->id, 'new_responsible_name' => $user->name, 'revision' => 1,
        ]);

        $backfill = app(BackfillDocumentEvents::class);
        $first = $backfill->execute();
        $ids = DB::table('document_events')->orderBy('source_type')->pluck('id')->all();
        $second = $backfill->execute();

        $this->assertSame(['documents' => 1, 'versions' => 1, 'responsibilities' => 1], $first);
        $this->assertSame(['documents' => 0, 'versions' => 0, 'responsibilities' => 0], $second);
        $this->assertSame($ids, DB::table('document_events')->orderBy('source_type')->pluck('id')->all());
        $this->assertDatabaseHas('document_events', ['source_id' => $version->id, 'type' => 'document.version_uploaded', 'origin' => 'legacy']);
        $this->assertDatabaseHas('document_events', ['source_id' => $history->id, 'type' => 'document.responsible_assigned']);
        $this->assertSame(0, DB::table('document_events')->whereNotNull('actor_name')->count());
        $this->assertSame(3, DB::table('document_events')->where('actor_user_id', $user->id)->count());
        $this->assertDatabaseMissing('document_events', ['type' => 'document.current_version_changed']);
        $this->assertTrue(Schema::hasColumns('document_events', [
            'document_id', 'institution_id', 'type', 'actor_user_id', 'actor_name',
            'version_id', 'detail', 'origin', 'source_type', 'source_id', 'occurred_at',
        ]));
    }

    public function test_source_identity_is_unique_and_model_instances_are_append_only(): void
    {
        [, $user, $document] = $this->context();
        app(BackfillDocumentEvents::class)->execute();
        $event = DocumentEvent::firstOrFail();

        try {
            $event->actor_name = 'Changed through save';
            $event->save();
            $this->fail('An event save should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Document events are append-only.', $exception->getMessage());
        }

        $event->refresh();

        try {
            $event->update(['actor_name' => 'Changed']);
            $this->fail('An event update should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Document events are append-only.', $exception->getMessage());
        }

        try {
            $document->events()->findOrFail($event->id)->delete();
            $this->fail('An event delete should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Document events are append-only.', $exception->getMessage());
        }

        try {
            $event->forceDelete();
            $this->fail('An event force-delete should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Document events are append-only.', $exception->getMessage());
        }

        $this->expectException(\Throwable::class);
        DB::table('document_events')->insert([
            'id' => fake()->uuid(), 'document_id' => $document->id,
            'institution_id' => $document->institution_id, 'type' => 'document.created',
            'actor_user_id' => $user->id, 'actor_name' => $user->name, 'detail' => '{}',
            'origin' => 'legacy', 'source_type' => $event->source_type,
            'source_id' => $event->source_id, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_structural_migration_is_separate_and_partial_backfill_retries_without_swallowing_errors(): void
    {
        [$institution, $user, $first] = $this->context();
        $second = Document::create([
            'name' => 'Second', 'status' => true, 'author_id' => $user->id,
            'institution_id' => $institution->id, 'node_id' => $first->node_id,
        ]);

        $structurePath = base_path('Modules/Documents/database/migrations/2026_09_21_000001_create_document_events_table.php');
        $dataPath = base_path('Modules/Documents/database/migrations/2026_09_21_000002_backfill_document_events.php');
        $this->assertStringNotContainsString('BackfillDocumentEvents', (string) file_get_contents($structurePath));

        Schema::drop('document_events');
        (require $structurePath)->up();
        $this->assertTrue(Schema::hasTable('document_events'));
        $this->assertDatabaseCount('document_events', 0);

        DB::unprepared("CREATE TRIGGER fail_one_backfill_event BEFORE INSERT ON document_events WHEN NEW.source_id = '{$second->id}' BEGIN SELECT RAISE(ABORT, 'non duplicate failure'); END");
        try {
            (require $dataPath)->up();
            $this->fail('A non-duplicate database error must surface.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('non duplicate failure', $exception->getMessage());
        }
        $this->assertGreaterThanOrEqual(1, DB::table('document_events')->count());

        DB::unprepared('DROP TRIGGER fail_one_backfill_event');
        (require $dataPath)->up();
        $this->assertSame(2, DB::table('document_events')->where('source_type', 'document')->count());
        $this->assertSame(['documents' => 0, 'versions' => 0, 'responsibilities' => 0], app(BackfillDocumentEvents::class)->execute());
    }

    private function context(): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $document = Document::create([
            'name' => 'Policy', 'status' => true, 'author_id' => $user->id,
            'institution_id' => $institution->id, 'node_id' => $node->id,
        ]);

        return [$institution, $user, $document];
    }
}
