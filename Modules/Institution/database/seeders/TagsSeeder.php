<?php

namespace Modules\Institution\Database\Seeders;

use Modules\Institution\Models\Tag;
use Illuminate\Database\Seeder;

class TagsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $institution_id = \Modules\Institution\Models\Institution::where('name', 'Test institution')->first()->id;

        Tag::create([
            'id' => '00000000-0000-0000-0000-000000000003',
            'name' => 'Important',
            'institution_id' => $institution_id,
        ]);

        $institutions = \Modules\Institution\Models\Institution::all();

        foreach ($institutions as $institution) {
            Tag::factory(10)->create([
                'institution_id' => $institution->id,
            ]);
        }
    }
}
