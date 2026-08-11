<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Institution\Models\Institution;
use Tests\TestCase;

class NodeIntegrityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rebuilds_id_paths_and_resequences_convertible_legacy_orders(): void
    {
        $migration = require database_path('migrations/2026_08_11_000002_backfill_and_constrain_node_integrity.php');
        $migration->down();

        $institution = Institution::factory()->create();
        $rootId = '10000000-0000-0000-0000-000000000001';
        $childId = '10000000-0000-0000-0000-000000000002';

        DB::table('nodes')->insert([
            [
                'id' => $rootId,
                'name' => 'Root',
                'path' => 'root',
                'depth' => 17,
                'order' => '010',
                'active' => true,
                'institution_id' => $institution->id,
                'parent_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $childId,
                'name' => 'Child',
                'path' => 'root/child',
                'depth' => 42,
                'order' => '20',
                'active' => true,
                'institution_id' => $institution->id,
                'parent_id' => $rootId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration->up();

        $root = DB::table('nodes')->where('id', $rootId)->first();
        $child = DB::table('nodes')->where('id', $childId)->first();

        $this->assertSame($rootId, $root->path);
        $this->assertSame($rootId.'/'.$childId, $child->path);
        $this->assertSame(0, $root->depth);
        $this->assertSame(1, $child->depth);
        $this->assertSame(1, $root->order);
        $this->assertSame(1, $child->order);
        $this->assertSame('integer', Schema::getColumnType('nodes', 'order'));
        $this->assertSame('text', Schema::getColumnType('nodes', 'path'));
    }
}
