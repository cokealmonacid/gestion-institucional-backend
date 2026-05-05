<?php

namespace Modules\Nodes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Nodes\Models\Node;

class NodesDatabaseSeeder extends Seeder
{
    public function run(): void
    {

        $institutions = \Modules\Institution\Models\Institution::all();

        foreach ($institutions as $institution) {
            Node::factory(10)->create([
                'institution_id' => $institution->id,
            ]);
        }
    }
}
