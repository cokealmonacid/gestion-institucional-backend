<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Rol;

class UsersRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
            $users = \App\Models\User::all();
            $roles = \App\Models\Rol::all();
    
            foreach ($users as $user) {
                if ($user->email == 'testapi@example.com') {
                    $user->roles()->attach($roles->where('type', 'admin')->first()->id);
                    continue;
                }

                $user->roles()->attach($roles->random()->id);
            }
    }
}
