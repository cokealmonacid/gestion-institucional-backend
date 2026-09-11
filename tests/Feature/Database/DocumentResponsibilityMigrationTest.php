<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentResponsibilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_defaults_history_constraints_and_delete_rules_are_enforced(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $document = Document::create(['name' => 'Existing', 'status' => true, 'institution_id' => $institution->id, 'node_id' => $node->id]);

        $this->assertTrue(Schema::hasColumns('documents', ['responsible_user_id', 'responsibility_revision']));
        $indexes = collect(Schema::getIndexes('documents'));
        $this->assertTrue($indexes->contains(fn (array $index) => $index['name'] === 'docs_resp_user_idx' && $index['columns'] === ['responsible_user_id']));
        $historyIndexes = collect(Schema::getIndexes('document_responsible_histories'));
        $this->assertTrue($historyIndexes->contains(fn (array $index) => $index['name'] === 'doc_resp_hist_doc_rev_uq' && $index['columns'] === ['document_id', 'revision'] && $index['unique']));
        $document->refresh();
        $this->assertNull($document->responsible_user_id);
        $this->assertSame(0, $document->responsibility_revision);

        $document->update(['responsible_user_id' => $user->id]);
        $this->expectException(\Throwable::class);
        $user->forceDelete();
    }

    public function test_history_snapshots_survive_physical_user_deletion(): void
    {
        $institution = Institution::factory()->create();
        $actor = User::factory()->for($institution)->create();
        $target = User::factory()->for($institution)->create(['name' => 'Snapshot Name']);
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $document = Document::create(['name' => 'Document', 'status' => true, 'institution_id' => $institution->id, 'node_id' => $node->id]);
        DB::table('document_responsible_histories')->insert([
            'id' => (string) Str::uuid(), 'document_id' => $document->id,
            'new_responsible_user_id' => $target->id, 'actor_user_id' => $actor->id,
            'new_responsible_name' => $target->name, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $target->forceDelete();
        $this->assertDatabaseHas('document_responsible_histories', [
            'document_id' => $document->id, 'new_responsible_user_id' => null,
            'new_responsible_name' => 'Snapshot Name', 'revision' => 1,
        ]);
    }

    public function test_up_backfills_existing_documents_and_down_is_complete(): void
    {
        $migration = require base_path('Modules/Documents/database/migrations/2026_09_11_000001_add_document_responsibility.php');
        $migration->down();

        try {
            $institution = Institution::factory()->create();
            $node = Node::factory()->for($institution)->create(['active' => true]);
            $document = Document::create(['name' => 'Pre-migration', 'status' => true, 'institution_id' => $institution->id, 'node_id' => $node->id]);
            $migration->up();

            $row = DB::table('documents')->where('id', $document->id)->first();
            $this->assertNull($row->responsible_user_id);
            $this->assertSame(0, $row->responsibility_revision);
            $this->assertTrue(Schema::hasTable('document_responsible_histories'));

            $migration->down();
            $this->assertFalse(Schema::hasColumn('documents', 'responsible_user_id'));
            $this->assertFalse(Schema::hasColumn('documents', 'responsibility_revision'));
            $this->assertFalse(Schema::hasTable('document_responsible_histories'));
        } finally {
            if (! Schema::hasColumn('documents', 'responsible_user_id')) {
                $migration->up();
            }
        }
    }

    public function test_every_new_mysql_identifier_is_explicit_stable_and_within_the_limit(): void
    {
        $migration = require base_path('Modules/Documents/database/migrations/2026_09_11_000001_add_document_responsibility.php');
        $identifiers = (new \ReflectionClass($migration))->getConstants();

        $this->assertSame([
            'DOCUMENT_RESPONSIBLE_INDEX' => 'docs_resp_user_idx',
            'DOCUMENT_RESPONSIBLE_FOREIGN' => 'docs_resp_user_fk',
            'HISTORY_DOCUMENT_INDEX' => 'doc_resp_hist_doc_idx',
            'HISTORY_DOCUMENT_FOREIGN' => 'doc_resp_hist_doc_fk',
            'HISTORY_PREVIOUS_USER_INDEX' => 'doc_resp_hist_prev_user_idx',
            'HISTORY_PREVIOUS_USER_FOREIGN' => 'doc_resp_hist_prev_user_fk',
            'HISTORY_NEW_USER_INDEX' => 'doc_resp_hist_new_user_idx',
            'HISTORY_NEW_USER_FOREIGN' => 'doc_resp_hist_new_user_fk',
            'HISTORY_ACTOR_INDEX' => 'doc_resp_hist_actor_idx',
            'HISTORY_ACTOR_FOREIGN' => 'doc_resp_hist_actor_fk',
            'HISTORY_DOCUMENT_REVISION_UNIQUE' => 'doc_resp_hist_doc_rev_uq',
        ], $identifiers);

        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
    }
}
