<?php

namespace Modules\Nodes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;

class NodesDatabaseSeeder extends Seeder
{
    public function run(): void
    {

        $institutions = Institution::all();

        foreach ($institutions as $institution) {
            Node::factory(10)
                ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
                ->create([
                    'institution_id' => $institution->id,
                ]);
        }
    }
}
