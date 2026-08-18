<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class ApiContractAudit extends Command
{
    private const HTTP_METHODS = ['DELETE', 'GET', 'OPTIONS', 'PATCH', 'POST', 'PUT'];

    protected $signature = 'api:contract:audit
        {--path=openapi/v1 : Directory containing the OpenAPI contract files}
        {--exceptions=openapi/runtime-only-routes.json : Versioned registry of deliberate runtime-only routes}
        {--only-undocumented : List only routes that are neither documented nor registered as runtime-only}';

    protected $description = 'Audit API routes against OpenAPI contracts and the explicit runtime-only registry';

    public function handle(): int
    {
        try {
            $documented = $this->loadDocumentedPaths();
            $exceptions = $this->loadRuntimeOnlyRoutes();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $routes = $this->runtimeRoutes();
        $runtimeKeys = $routes->pluck('key')->flip();
        $issues = collect();

        foreach ($exceptions as $key => $exception) {
            if (! $runtimeKeys->has($key)) {
                $issues->push("Runtime-only exception does not match a registered route: {$key}.");
            } elseif (isset($documented[$key])) {
                $issues->push("Documented route is unnecessarily excepted as runtime-only: {$key}.");
            }
        }

        $rows = $routes->map(function (array $route) use ($documented, $exceptions) {
            $coverage = isset($documented[$route['key']])
                ? 'documented'
                : (isset($exceptions[$route['key']]) ? 'runtime-only' : 'undocumented');

            return $route + [
                'coverage' => $coverage,
                'reason' => $exceptions[$route['key']]['reason'] ?? '',
            ];
        });
        $undocumented = $rows->where('coverage', 'undocumented');

        if ($this->option('only-undocumented')) {
            $rows = $undocumented;
        }

        $this->table(
            ['Method', 'URI', 'Coverage', 'Reason'],
            $rows->map(fn (array $row) => [$row['method'], $row['uri'], $row['coverage'], $row['reason']]),
        );

        foreach ($issues as $issue) {
            $this->error($issue);
        }

        $documentedCount = $routes->filter(fn (array $route) => isset($documented[$route['key']]))->count();
        $runtimeOnlyCount = $routes->filter(fn (array $route) => isset($exceptions[$route['key']]) && ! isset($documented[$route['key']]))->count();
        $this->newLine();
        $this->info("{$routes->count()} runtime route(s): {$documentedCount} documented, {$runtimeOnlyCount} runtime-only, {$undocumented->count()} undocumented; {$issues->count()} registry issue(s).");

        return $undocumented->isEmpty() && $issues->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, true> */
    private function loadDocumentedPaths(): array
    {
        $dir = base_path($this->option('path'));
        if (! is_dir($dir)) {
            throw new \RuntimeException("OpenAPI directory does not exist: {$this->option('path')}.");
        }

        $documented = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $contract = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($contract['paths'] ?? [] as $path => $operations) {
                foreach (array_keys($operations) as $method) {
                    $method = strtoupper($method);
                    if (in_array($method, self::HTTP_METHODS, true)) {
                        $documented[$method.' '.$this->normalizePath($path)] = true;
                    }
                }
            }
        }

        return $documented;
    }

    /** @return array<string, array{method: string, path: string, reason: string}> */
    private function loadRuntimeOnlyRoutes(): array
    {
        $file = base_path($this->option('exceptions'));
        if (! is_file($file)) {
            throw new \RuntimeException("Runtime-only registry does not exist: {$this->option('exceptions')}.");
        }

        $registry = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (($registry['version'] ?? null) !== 1 || ! is_array($registry['routes'] ?? null)) {
            throw new \RuntimeException('Runtime-only registry must declare version 1 and a routes array.');
        }

        $routes = [];
        foreach ($registry['routes'] as $index => $entry) {
            $method = strtoupper($entry['method'] ?? '');
            $path = $entry['path'] ?? '';
            $reason = trim($entry['reason'] ?? '');

            if (! in_array($method, self::HTTP_METHODS, true)
                || ! is_string($path)
                || $path !== $this->normalizePath($path)
                || ! str_starts_with($path, '/api/')
                || $reason === '') {
                throw new \RuntimeException("Invalid runtime-only route at index {$index}.");
            }

            $key = $method.' '.$path;
            if (isset($routes[$key])) {
                throw new \RuntimeException("Duplicate runtime-only route: {$key}.");
            }

            $routes[$key] = ['method' => $method, 'path' => $path, 'reason' => $reason];
        }

        return $routes;
    }

    /** @return Collection<int, array{key: string, method: string, uri: string}> */
    private function runtimeRoutes(): Collection
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->flatMap(function ($route) {
                $uri = $this->normalizePath('/'.$route->uri());

                return collect($route->methods())->reject(fn (string $method) => $method === 'HEAD')
                    ->map(fn (string $method) => [
                        'key' => strtoupper($method).' '.$uri,
                        'method' => strtoupper($method),
                        'uri' => $uri,
                    ]);
            })
            ->unique('key')
            ->sortBy('key')
            ->values();
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
