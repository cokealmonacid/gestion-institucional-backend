<?php

namespace App\Console\Commands;

use App\Services\Demo\UniversidadDelRioDemoLoader;
use Illuminate\Console\Command;

class SeedUniversidadDelRioDemo extends Command
{
    protected $signature = 'acervo:demo:universidad-rio
        {--check : Inspeccionar el escenario sin realizar escrituras}
        {--force : Autorizar expresamente la carga en producción}';

    protected $description = 'Carga explícitamente el escenario ficticio Universidad del Río';

    public function handle(UniversidadDelRioDemoLoader $loader): int
    {
        if (! $this->option('check') && app()->environment('production') && ! $this->option('force')) {
            $this->error('En producción debe autorizar explícitamente la carga con --force.');

            return self::FAILURE;
        }

        try {
            $result = $this->option('check') ? $loader->inspect() : $loader->load();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Estado: {$result['status']}");
        $this->line($result['message']);
        foreach ($result['details'] as $detail) {
            $this->line('- '.$detail);
        }
        if ($this->option('check')) {
            $this->line('Nota: --check sólo verifica lecturas existentes; no prueba permisos de escritura en el almacenamiento documental.');
        }

        return in_array($result['status'], ['absent', 'complete'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
