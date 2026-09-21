<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentVersionIntegrityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_valid_numbers_and_repairs_only_invalid_or_duplicate_rows(): void
    {
        $migration = $this->migrationWithoutConstraints();
        Schema::table('document_versions', function (Blueprint $table) {
            $table->float('version_number')->nullable()->change();
        });
        [$document, $author, $node] = $this->context();
        $sameTime = '2026-01-01 00:00:00';
        $ids = [
            'one' => '10000000-0000-0000-0000-000000000001',
            'three' => '10000000-0000-0000-0000-000000000003',
            'five' => '10000000-0000-0000-0000-000000000005',
            'duplicate' => '20000000-0000-0000-0000-000000000005',
            'null' => '30000000-0000-0000-0000-000000000001',
            'zero' => '30000000-0000-0000-0000-000000000002',
            'negative' => '30000000-0000-0000-0000-000000000003',
            'fraction' => '30000000-0000-0000-0000-000000000004',
        ];
        foreach ([
            [$ids['one'], 1, $sameTime], [$ids['three'], 3, $sameTime], [$ids['five'], 5, $sameTime],
            [$ids['duplicate'], 5, $sameTime], [$ids['null'], null, $sameTime], [$ids['zero'], 0, $sameTime],
            [$ids['negative'], -2, $sameTime], [$ids['fraction'], 2.5, $sameTime],
        ] as [$id, $number, $created]) {
            $this->insertVersion($document, $author, $node, $id, $number, $created);
        }

        $migration->up();
        $numbers = DB::table('document_versions')->pluck('version_number', 'id')->map(fn ($value) => (int) $value);
        $this->assertSame(1, $numbers[$ids['one']]);
        $this->assertSame(3, $numbers[$ids['three']]);
        $this->assertSame(5, $numbers[$ids['five']]);
        $this->assertSame([6, 7, 8, 9, 10], collect([$ids['duplicate'], $ids['null'], $ids['zero'], $ids['negative'], $ids['fraction']])->map(fn ($id) => $numbers[$id])->all());
    }

    public function test_migration_preserves_a_single_seven_and_documents_without_versions(): void
    {
        $migration = $this->migrationWithoutConstraints();
        [$document, $author, $node] = $this->context();
        $this->insertVersion($document, $author, $node, (string) Str::uuid(), 7, now()->toDateTimeString());
        $this->context();

        $migration->up();

        $this->assertSame(7, (int) DB::table('document_versions')->where('document_id', $document->id)->value('version_number'));
    }

    public function test_database_enforces_current_equivalence_activity_and_document_scope(): void
    {
        [$document, $author, $node] = $this->context();
        [$otherDocument, $otherAuthor, $otherNode] = $this->context();
        $this->insertVersion($document, $author, $node, (string) Str::uuid(), 1, now()->toDateTimeString(), true, true);
        $this->insertVersion($otherDocument, $otherAuthor, $otherNode, (string) Str::uuid(), 1, now()->toDateTimeString(), true, true);
        $this->assertSame(2, DB::table('document_versions')->where('current_marker', 1)->count());

        foreach ([
            ['active' => false, 'is_current' => true],
            ['active' => true, 'is_current' => true],
        ] as $index => $state) {
            try {
                $this->insertVersion($document, $author, $node, (string) Str::uuid(), $index + 2, now()->toDateTimeString(), $state['active'], $state['is_current']);
                $this->fail('The invalid current state was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([2, 1] as $marker) {
            try {
                DB::table('document_versions')->insert([
                    'id' => (string) Str::uuid(), 'version_number' => 10 + $marker, 'url' => 'private/file',
                    'filename' => 'file.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1,
                    'author_id' => $author->id, 'document_id' => $document->id, 'institution_id' => $document->institution_id,
                    'node_id' => $node->id, 'active' => $marker === 1, 'is_current' => false, 'current_marker' => $marker,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->fail('A generated current marker was writable.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            DB::table('document_versions')->insert([
                'id' => (string) Str::uuid(), 'version_number' => 20, 'url' => 'private/file',
                'filename' => 'file.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1,
                'author_id' => $author->id, 'document_id' => $document->id, 'institution_id' => $document->institution_id,
                'node_id' => $node->id, 'active' => true, 'is_current' => true, 'current_marker' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('A generated current marker was writable as null.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_down_preserves_document_foreign_key_and_index_and_up_restores_invariants(): void
    {
        $migration = require base_path('Modules/Documents/database/migrations/2026_08_17_000001_enforce_document_version_invariants.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('document_versions', 'current_marker'));
        $indexes = collect(Schema::getIndexes('document_versions'));
        $this->assertTrue($indexes->contains(fn (array $index) => $index['name'] === 'document_versions_document_id_index'
            && $index['columns'] === ['document_id']));
        $this->assertFalse($indexes->contains(fn (array $index) => in_array($index['name'], [
            'document_versions_number_unique',
            'document_versions_current_unique',
        ], true)));

        $foreignKeys = collect(Schema::getForeignKeys('document_versions'));
        $this->assertTrue($foreignKeys->contains(fn (array $foreignKey) => $foreignKey['columns'] === ['document_id']
            && $foreignKey['foreign_table'] === 'documents'
            && $foreignKey['foreign_columns'] === ['id']));
        $this->assertLifecycleCheckDoesNotExist();

        $migration->up();

        $this->assertTrue(Schema::hasColumn('document_versions', 'current_marker'));
        $restoredIndexes = collect(Schema::getIndexes('document_versions'));
        $this->assertTrue($restoredIndexes->contains(fn (array $index) => $index['name'] === 'document_versions_number_unique' && $index['unique']));
        $this->assertTrue($restoredIndexes->contains(fn (array $index) => $index['name'] === 'document_versions_current_unique' && $index['unique']));
        $this->assertTrue($restoredIndexes->contains(fn (array $index) => $index['name'] === 'document_versions_document_id_index'
            && $index['columns'] === ['document_id']));
        $this->assertLifecycleCheckExists();
    }

    private function migrationWithoutConstraints(): object
    {
        $migration = require base_path('Modules/Documents/database/migrations/2026_08_17_000001_enforce_document_version_invariants.php');
        $migration->down();

        return $migration;
    }

    private function assertLifecycleCheckDoesNotExist(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(0, DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', ['document_versions_active_current_insert', 'document_versions_active_current_update'])
                ->count());

            return;
        }

        $this->assertSame(0, DB::table('information_schema.check_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('constraint_name', 'document_versions_active_current_check')
            ->count());
    }

    private function assertLifecycleCheckExists(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(2, DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', ['document_versions_active_current_insert', 'document_versions_active_current_update'])
                ->count());

            return;
        }

        $this->assertSame(1, DB::table('information_schema.check_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('constraint_name', 'document_versions_active_current_check')
            ->count());
    }

    /** @return array{Document, User, Node} */
    private function context(): array
    {
        $institution = Institution::factory()->create();
        $author = User::factory()->for($institution)->create();
        $node = new Node;
        $node->id = $node->newUniqueId();
        $node->fill(['name' => 'Records', 'path' => $node->id, 'depth' => 0, 'order' => 1, 'active' => true, 'institution_id' => $institution->id]);
        $node->save();
        $document = Document::create(['name' => 'Policy', 'status' => true, 'author_id' => $author->id, 'institution_id' => $institution->id, 'node_id' => $node->id]);

        return [$document, $author, $node];
    }

    private function insertVersion(Document $document, User $author, Node $node, string $id, mixed $number, string $created, bool $active = true, bool $current = false): void
    {
        DB::table('document_versions')->insert([
            'id' => $id, 'version_number' => $number, 'url' => 'private/'.$id, 'filename' => 'policy.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 1, 'author_id' => $author->id,
            'document_id' => $document->id, 'institution_id' => $document->institution_id, 'node_id' => $node->id,
            'active' => $active, 'is_current' => $current, 'created_at' => $created, 'updated_at' => $created,
        ]);
    }
}
