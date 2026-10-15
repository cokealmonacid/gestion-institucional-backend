<?php

namespace Database\Seeders;

use App\Enums\RoleType;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate([
                'type' => $role,
            ]);
        }
    }
}
