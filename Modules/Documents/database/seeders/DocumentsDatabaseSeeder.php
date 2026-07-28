<?php

namespace Modules\Documents\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use App\Models\User;

class DocumentsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Document::create([
            'id' => '00000000-0000-0000-0000-000000000002',
            'name' => 'Documento Base',
            'description' => 'Documento base de la institución principal.',
            'category' => 'Normativa',
            'responsible_unit' => 'Rectoría',
            'status' => true,
            'author_id' => null,
            'institution_id' => '00000000-0000-0000-0000-000000000001',
            'node_id' => null,
        ]);

        $institutions = Institution::all();
        $categories = ['Normativa', 'Reglamento', 'Circular', 'Resolución', 'Acuerdo', 'Informe', 'Acta', 'Manual'];
        $units = ['Rectoría', 'Secretaría General', 'Dirección Académica', 'Dirección Administrativa', 'Decanato', 'Coordinación'];

        foreach ($institutions as $institution) {
            $users = User::where('institution_id', $institution->id)->get();
            $nodes = Node::where('institution_id', $institution->id)->get();

            $count = (int) ceil(100 / $institutions->count());

            for ($i = 0; $i < $count; $i++) {
                Document::create([
                    'name' => fake()->sentence(3),
                    'description' => fake()->paragraph(),
                    'category' => fake()->randomElement($categories),
                    'responsible_unit' => fake()->randomElement($units),
                    'status' => fake()->boolean(80),
                    'author_id' => $users->isNotEmpty() ? $users->random()->id : null,
                    'institution_id' => $institution->id,
                    'node_id' => $nodes->isNotEmpty() ? $nodes->random()->id : null,
                ]);
            }
        }
    }
}
