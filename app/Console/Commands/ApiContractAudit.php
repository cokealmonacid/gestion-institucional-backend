<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class ApiContractAudit extends Command
{
    protected $signature = 'api:contract:audit
        {--path=openapi/v1 : Directory containing the OpenAPI contract files}
        {--only-undocumented : List only routes missing from the contracts}';

    protected $description = 'Compare registered API routes against the OpenAPI contracts and report undocumented endpoints';

    public function handle(): int
    {
        $documented = $this->loadDocumentedPaths();

        $rows = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->flatMap(function ($route) use ($documented) {
                $uri = '/'.$route->uri();
                $methods = collect($route->methods())->reject(fn ($m) => $m === 'HEAD');

                return $methods->map(fn ($method) => [
                    'method' => $method,
                    'uri' => $uri,
                    'documented' => isset($documented[$uri][strtolower($method)]),
                ]);
            })
            ->unique(fn ($row) => $row['method'].' '.$row['uri'])
            ->sortBy(fn ($row) => $row['uri'].$row['method'])
            ->values();

        if ($this->option('only-undocumented')) {
            $rows = $rows->reject(fn ($row) => $row['documented']);
        }

        $this->table(
            ['Method', 'URI', 'Documented'],
            $rows->map(fn ($row) => [
                $row['method'],
                $row['uri'],
                $row['documented'] ? '✅' : '❌',
            ])
        );

        $missing = $rows->reject(fn ($row) => $row['documented'])->count();
        $total = $rows->count();

        $this->newLine();
        $this->info("{$total} route(s) checked, {$missing} undocumented.");

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array<string, true>> keyed by path then lowercase HTTP method
     */
    private function loadDocumentedPaths(): array
    {
        $dir = base_path($this->option('path'));
        $documented = [];

        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $contract = json_decode(file_get_contents($file), true);

            foreach ($contract['paths'] ?? [] as $path => $operations) {
                foreach (array_keys($operations) as $method) {
                    $documented[$path][strtolower($method)] = true;
                }
            }
        }

        return $documented;
    }
}
