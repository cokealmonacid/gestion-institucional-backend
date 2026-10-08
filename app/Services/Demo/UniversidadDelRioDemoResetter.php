<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Documents\Actions\StoreDocumentVersionAction;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Nodes\Models\Node;
use RuntimeException;

class UniversidadDelRioDemoResetter
{
    public function __construct(
        private readonly UniversidadDelRioDemoResetPlan $plans,
        private readonly UniversidadDelRioDemoLoader $loader,
        private readonly StoreDocumentVersionAction $versions,
        private readonly UniversidadDelRioDemoFileCleaner $cleaner,
    ) {}

    /** @return array<string, mixed> */
    public function preview(): array
    {
        return $this->plans->preview();
    }

    /** @return array<string, mixed> */
    public function reset(string $token): array
    {
        $tokenData = $this->plans->validateToken($token);
        $operationId = (string) Str::uuid();
        $createdFiles = [];
        $oldFiles = [];
        $diskName = $this->versions->storageDisk();

        try {
            DB::transaction(function () use ($tokenData, &$createdFiles, &$oldFiles): void {
                $current = $this->plans->inventory(lock: true);
                if (! hash_equals($tokenData['inventory_hash'], $current['inventory_hash'])) {
                    throw new RuntimeException('El plan quedó obsoleto. Genere una nueva previsualización.');
                }

                $oldFiles = $current['files'];
                $forbiddenPaths = collect($oldFiles)->pluck('path')->filter()->values()->all();
                $users = [];
                foreach (UniversidadDelRioDemoLoader::userDefinitions() as $key => $definition) {
                    $users[$key] = User::query()->whereKey($definition['id'])->lockForUpdate()->firstOrFail();
                }

                Document::query()
                    ->where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)
                    ->delete();
                Node::query()
                    ->where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)
                    ->orderByDesc('depth')
                    ->orderByDesc('id')
                    ->get()
                    ->each->delete();

                $this->loader->populate($users, $createdFiles, $forbiddenPaths);
                $result = $this->loader->inspect();
                if ($result['status'] !== 'complete') {
                    throw new RuntimeException('La reconstrucción no produjo el escenario inicial completo.');
                }
            });
        } catch (\Throwable $exception) {
            $pending = $this->compensate($diskName, $createdFiles);
            $suffix = $pending === []
                ? ''
                : ' La compensación quedó incompleta para: '.implode(', ', $pending).'.';
            throw new RuntimeException($exception->getMessage().$suffix, previous: $exception);
        }

        $cleanup = $this->cleanupOldFiles($diskName, $oldFiles, $createdFiles);
        if ($cleanup['pending'] !== []) {
            Log::error('Universidad del Río demo reset completed with incomplete storage cleanup.', [
                'operation_id' => $operationId,
                'pending_paths' => $cleanup['pending'],
            ]);
        }

        return [
            'status' => $cleanup['pending'] === [] ? 'reset_complete' : 'reset_complete_cleanup_incomplete',
            'message' => $cleanup['pending'] === []
                ? 'Restablecimiento completado.'
                : 'Restablecimiento completado; limpieza incompleta.',
            'operation_id' => $operationId,
            'deleted_files' => $cleanup['deleted'],
            'omitted_files' => $cleanup['omitted'],
            'pending_files' => $cleanup['pending'],
        ];
    }

    /** @param list<string> $createdFiles @return list<string> */
    private function compensate(string $diskName, array $createdFiles): array
    {
        $pending = [];
        foreach (array_unique($createdFiles) as $path) {
            if (! $this->cleaner->deleteExact($diskName, $path)) {
                $pending[] = $path;
            }
        }

        return $pending;
    }

    /** @param list<array<string, mixed>> $oldFiles @param list<string> $newFiles @return array{deleted: list<string>, omitted: list<array{path: string, reason: string}>, pending: list<string>} */
    private function cleanupOldFiles(string $diskName, array $oldFiles, array $newFiles): array
    {
        $deleted = [];
        $omitted = [];
        $pending = [];
        foreach ($oldFiles as $candidate) {
            $path = (string) $candidate['path'];
            if (! $candidate['eligible']) {
                $omitted[] = ['path' => $path, 'reason' => (string) $candidate['reason']];

                continue;
            }
            if (in_array($path, $newFiles, true)) {
                $omitted[] = ['path' => $path, 'reason' => 'new_file'];

                continue;
            }
            if (DocumentVersion::query()->where('url', $path)->exists()) {
                $omitted[] = ['path' => $path, 'reason' => 'referenced_after_commit'];

                continue;
            }
            if ($this->cleaner->deleteExact($diskName, $path)) {
                $deleted[] = $path;
            } else {
                $pending[] = $path;
            }
        }

        return compact('deleted', 'omitted', 'pending');
    }
}
