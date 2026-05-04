<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $institutions = \Modules\Institution\Models\Institution::all();
        
        foreach ($institutions as $institution) {
            User::factory()->create([
                'password' => '12345678',
                'institution_id' => $institution->id,
            ]);
        }
    }
}
