<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Institution\Models\Institution;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $institution = Institution::where('name', 'Test institution')->first();

        User::factory()->create([
            'email' => 'testapi@example.com',
            'password' => '12345678',
            'institution_id' => $institution->id,
            'active' => true,
        ]);

        User::factory()->create([
            'email' => 'inactive-testapi@example.com',
            'password' => '12345678',
            'institution_id' => $institution->id,
            'active' => false,
        ]);

        $institutions = Institution::all();

        foreach ($institutions as $institution) {
            User::factory()->create([
                'password' => '12345678',
                'institution_id' => $institution->id,
                'active' => true,
            ]);
        }
    }
}
