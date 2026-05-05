<?php

namespace Modules\Documents\Database\Seeders;

use Modules\Documents\Models\Tag;
use Illuminate\Database\Seeder;

class TagsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $institutions = \Modules\Institution\Models\Institution::all();

        foreach ($institutions as $institution) {
            Tag::factory(10)->create([
                'institution_id' => $institution->id,
            ]);
        }
    }
}
