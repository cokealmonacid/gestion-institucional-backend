<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Modules\Nodes\Support\NodeName;
use Tests\TestCase;

class NodesFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_canonical_root(): void
    {
        $node = Node::factory()->create(['name' => '  Políticas  ']);

        $this->assertNull($node->parent_id);
        $this->assertSame('R', $node->parent_scope);
        $this->assertSame($node->id, $node->path);
        $this->assertSame(0, $node->depth);
        $this->assertSame(1, $node->order);
        $this->assertCanonicalName($node, 'Políticas');
    }

    public function test_factory_derives_a_child_and_grandchild_from_real_parents(): void
    {
        $institution = Institution::factory()->create();
        $root = Node::factory()->create(['institution_id' => $institution->id, 'name' => 'Root']);
        $child = Node::factory()->create(['parent_id' => $root->id, 'name' => 'Child']);
        $grandchild = Node::factory()->create(['parent_id' => $child->id, 'name' => 'Grandchild']);

        $this->assertStructure($child, $root);
        $this->assertStructure($grandchild, $child);
        $this->assertSame($institution->id, $child->institution_id);
        $this->assertSame($institution->id, $grandchild->institution_id);
        $this->assertCanonicalName($child, 'Child');
        $this->assertCanonicalName($grandchild, 'Grandchild');
    }

    public function test_multiple_factory_siblings_receive_collision_free_sequential_orders(): void
    {
        $parent = Node::factory()->create();
        $siblings = collect(['One', 'Two', 'Three', 'Four'])
            ->map(fn (string $name) => Node::factory()->create([
                'parent_id' => $parent->id,
                'name' => $name,
            ]));

        $this->assertSame([1, 2, 3, 4], $siblings->pluck('order')->all());
        $this->assertSame(4, $siblings->pluck('order')->unique()->count());
        $this->assertSame(['P:'.$parent->id], $siblings->pluck('parent_scope')->unique()->values()->all());

        foreach ($siblings as $sibling) {
            $this->assertStructure($sibling, $parent);
            $this->assertCanonicalName($sibling, $sibling->name);
        }
    }

    private function assertStructure(Node $node, Node $parent): void
    {
        $this->assertSame($parent->id, $node->parent_id);
        $this->assertSame('P:'.$parent->id, $node->parent_scope);
        $this->assertSame($parent->path.'/'.$node->id, $node->path);
        $this->assertSame($parent->depth + 1, $node->depth);
    }

    private function assertCanonicalName(Node $node, string $expectedDisplay): void
    {
        $canonical = NodeName::normalize($expectedDisplay);

        $this->assertSame($canonical['display'], $node->name);
        $this->assertSame($canonical['normalized'], $node->normalized_name);
        $this->assertSame($canonical['fingerprint'], $node->name_fingerprint);
    }
}
