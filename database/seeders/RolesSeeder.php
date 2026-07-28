<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Rol;
use App\Enums\RoleType;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleType::cases() as $role) {
            Rol::factory()->create([
                'type' => $role,
            ]);
        }
    }
}
