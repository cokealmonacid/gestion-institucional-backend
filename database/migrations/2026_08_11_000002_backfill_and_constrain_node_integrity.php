<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROOT_SCOPE = 'R';

    public function up(): void
    {
        $nodes = DB::table('nodes')->get()->keyBy('id');
        $resolved = [];
        $visiting = [];
        $prepared = [];

        foreach ($nodes as $node) {
            $name = (string) $node->name;
            $normalizedName = Normalizer::normalize($name, Normalizer::FORM_C);

            if ($normalizedName === false) {
                throw new RuntimeException("Node {$node->id} has a name that cannot be normalized to NFC.");
            }

            if ($normalizedName !== $name
                || trim($name) !== $name
                || mb_strlen($name, 'UTF-8') < 1
                || mb_strlen($name, 'UTF-8') > 255
                || preg_match('/[\/\\\\\p{Cc}]/u', $name) === 1) {
                throw new RuntimeException("Node {$node->id} has a name incompatible with the canonical node-name rules.");
            }

            $normalizedName = mb_convert_case($normalizedName, MB_CASE_FOLD, 'UTF-8');
            $order = trim((string) $node->order);

            $orderWithoutLeadingZeros = ltrim($order, '0') ?: '0';
            if (! preg_match('/^[0-9]+$/', $order)
                || strlen($orderWithoutLeadingZeros) > 10
                || (strlen($orderWithoutLeadingZeros) === 10 && strcmp($orderWithoutLeadingZeros, '4294967295') > 0)) {
                throw new RuntimeException("Node {$node->id} has an incompatible order value: {$node->order}.");
            }

            $prepared[$node->id] = [
                'normalized_name' => $normalizedName,
                'name_fingerprint' => hash('sha256', $normalizedName),
                'parent_scope' => $node->parent_id === null ? self::ROOT_SCOPE : 'P:'.$node->parent_id,
                'legacy_order' => (int) $order,
            ];
        }

        foreach ($nodes as $node) {
            $this->resolvePath($node->id, $nodes, $resolved, $visiting);
        }

        $duplicateNames = collect($prepared)
            ->groupBy(fn (array $item, string $id) => $nodes[$id]->institution_id."\0".$item['parent_scope']."\0".$item['name_fingerprint'])
            ->first(fn ($group) => $group->count() > 1);

        if ($duplicateNames !== null) {
            throw new RuntimeException('Existing nodes contain duplicate normalized sibling names. Resolve them before retrying the migration.');
        }

        // Resequence deterministically per institution and parent. Duplicate and gapped legacy
        // positions are preserved in relative order by name and UUID, then become 1..n.
        $groups = collect(array_keys($prepared))->groupBy(
            fn (string $id) => $nodes[$id]->institution_id."\0".$prepared[$id]['parent_scope']
        );

        foreach ($groups as $ids) {
            $sorted = $ids->sort(function (string $left, string $right) use ($nodes, $prepared) {
                return [$prepared[$left]['legacy_order'], $nodes[$left]->name, $left]
                    <=> [$prepared[$right]['legacy_order'], $nodes[$right]->name, $right];
            })->values();

            foreach ($sorted as $offset => $id) {
                $prepared[$id]['position'] = $offset + 1;
            }
        }

        foreach ($prepared as $id => $values) {
            $depth = substr_count($resolved[$id], '/');
            if ($depth > 100) {
                throw new RuntimeException("Node {$id} exceeds the supported maximum depth of 100.");
            }

            DB::table('nodes')->where('id', $id)->update([
                'path_ids' => $resolved[$id],
                'depth' => $depth,
                'position' => $values['position'],
                'parent_scope' => $values['parent_scope'],
                'normalized_name' => $values['normalized_name'],
                'name_fingerprint' => $values['name_fingerprint'],
            ]);
        }

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['path', 'order']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->renameColumn('path_ids', 'path');
            $table->renameColumn('position', 'order');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->text('path')->nullable(false)->change();
            $table->unsignedInteger('order')->nullable(false)->change();
            $table->string('parent_scope', 38)->nullable(false)->change();
            $table->text('normalized_name')->nullable(false)->change();
            $table->char('name_fingerprint', 64)->nullable(false)->change();
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->unique(
                ['institution_id', 'parent_scope', 'name_fingerprint'],
                'nodes_sibling_name_unique'
            );
            $table->unique(
                ['institution_id', 'parent_scope', 'order'],
                'nodes_sibling_order_unique'
            );
            $table->index(['institution_id', 'parent_id', 'active'], 'nodes_navigation_index');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex('nodes_navigation_index');
            $table->dropUnique('nodes_sibling_order_unique');
            $table->dropUnique('nodes_sibling_name_unique');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->string('parent_scope', 38)->nullable()->change();
            $table->text('normalized_name')->nullable()->change();
            $table->char('name_fingerprint', 64)->nullable()->change();
            $table->string('legacy_path', 255)->nullable();
            $table->string('legacy_order', 255)->nullable();
        });

        DB::table('nodes')->orderBy('depth')->orderBy('id')->eachById(function ($node) {
            DB::table('nodes')->where('id', $node->id)->update([
                'legacy_path' => $node->path,
                'legacy_order' => (string) $node->order,
            ]);
        }, column: 'id');

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['path', 'order']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->renameColumn('legacy_path', 'path');
            $table->renameColumn('legacy_order', 'order');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->text('path_ids')->nullable();
            $table->unsignedInteger('position')->nullable();
        });
    }

    private function resolvePath(string $id, $nodes, array &$resolved, array &$visiting): string
    {
        if (isset($resolved[$id])) {
            return $resolved[$id];
        }

        if (isset($visiting[$id])) {
            throw new RuntimeException("Cycle detected in the node hierarchy at {$id}.");
        }

        $visiting[$id] = true;
        $node = $nodes[$id];

        if ($node->parent_id === null) {
            $path = $id;
        } else {
            $parent = $nodes->get($node->parent_id);

            if ($parent === null) {
                throw new RuntimeException("Node {$id} references missing parent {$node->parent_id}.");
            }

            if ($parent->institution_id !== $node->institution_id) {
                throw new RuntimeException("Node {$id} references a parent from another institution.");
            }

            $path = $this->resolvePath($parent->id, $nodes, $resolved, $visiting).'/'.$id;
        }

        unset($visiting[$id]);

        return $resolved[$id] = $path;
    }
};
