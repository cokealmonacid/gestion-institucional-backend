<?php

namespace Database\Seeders;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            \Modules\Institution\Database\Seeders\InstitutionsDatabaseSeeder::class,
            UsersSeeder::class,
            \Modules\Nodes\Database\Seeders\NodesDatabaseSeeder::class,
            \Modules\Documents\Database\Seeders\DocumentsDatabaseSeeder::class,
        ]);
    }
}
