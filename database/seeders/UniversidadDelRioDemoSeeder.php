<?php

namespace Database\Seeders;

use App\Services\Demo\UniversidadDelRioDemoLoader;
use Illuminate\Database\Seeder;

class UniversidadDelRioDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(UniversidadDelRioDemoLoader::class)->load();
    }
}
