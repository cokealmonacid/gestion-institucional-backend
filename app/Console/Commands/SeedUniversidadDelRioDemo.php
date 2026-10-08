<?php

namespace App\Console\Commands;

use App\Services\Demo\UniversidadDelRioDemoLoader;
use App\Services\Demo\UniversidadDelRioDemoResetter;
use Illuminate\Console\Command;

class SeedUniversidadDelRioDemo extends Command
{
    protected $signature = 'acervo:demo:universidad-rio
        {--check : Inspeccionar el escenario sin realizar escrituras}
        {--reset : Previsualizar o ejecutar el restablecimiento acotado}
        {--plan-token= : Token vigente generado por --reset --check}
        {--force : Autorizar expresamente la carga en producción}';

    protected $description = 'Carga explícitamente el escenario ficticio Universidad del Río';

    public function handle(UniversidadDelRioDemoLoader $loader, UniversidadDelRioDemoResetter $resetter): int
    {
        if ($this->option('plan-token') !== null && ! $this->option('reset')) {
            $this->error('--plan-token sólo puede utilizarse junto con --reset.');

            return self::FAILURE;
        }
        if ($this->option('reset')) {
            return $this->handleReset($resetter);
        }
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

    private function handleReset(UniversidadDelRioDemoResetter $resetter): int
    {
        if ($this->option('check')) {
            if ($this->option('force') || $this->option('plan-token') !== null) {
                $this->error('--reset --check no admite --force ni --plan-token.');

                return self::FAILURE;
            }
            try {
                $plan = $resetter->preview();
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            $this->line('Previsualización de restablecimiento (sin escrituras)');
            $this->line("Institución: {$plan['institution_id']}");
            foreach ($plan['counts'] as $type => $count) {
                $this->line("- {$type}: {$count}");
            }
            $this->line('Cuentas conservadas:');
            foreach ($plan['accounts'] as $account) {
                $this->line("- {$account['id']} {$account['email']} [".implode(', ', $account['roles']).']');
            }
            $this->line('Registros estructurales afectados:');
            foreach (['nodes', 'documents', 'versions'] as $type) {
                foreach ($plan['records'][$type] as $record) {
                    $label = $record['name'] ?? $record['filename'] ?? '';
                    $this->line("- {$type}: {$record['id']} {$label}");
                }
            }
            $this->line('Archivos candidatos:');
            foreach ($plan['files'] as $file) {
                $this->line("- [{$file['reason']}] {$file['path']}");
            }
            $this->line("Caduca: {$plan['expires_at']}");
            $this->line("Token: {$plan['token']}");

            return self::SUCCESS;
        }

        $token = $this->option('plan-token');
        if (! $this->option('force') || ! is_string($token) || $token === '') {
            $this->error('El restablecimiento exige --force y un --plan-token vigente.');

            return self::FAILURE;
        }
        try {
            $result = $resetter->reset($token);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line($result['message']);
        $this->line("Operación: {$result['operation_id']}");
        foreach ($result['deleted_files'] as $path) {
            $this->line("- eliminado: {$path}");
        }
        foreach ($result['omitted_files'] as $file) {
            $this->line("- omitido [{$file['reason']}]: {$file['path']}");
        }
        foreach ($result['pending_files'] as $path) {
            $this->line("- pendiente: {$path}");
        }

        return $result['status'] === 'reset_complete' ? self::SUCCESS : self::FAILURE;
    }
}
