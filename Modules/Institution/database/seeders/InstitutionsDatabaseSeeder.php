<?php

namespace Modules\Institution\Database\Seeders;

use Modules\Institution\Models\Institution;
use Illuminate\Database\Seeder;
use Modules\Institution\Database\Seeders\TagsSeeder;

class InstitutionsDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Institution::factory(10)->create();
        $this->call(TagsSeeder::class);
    }
}
