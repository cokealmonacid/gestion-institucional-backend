<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApiContractAuditTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    public function test_repository_routes_are_documented_or_explicitly_runtime_only(): void
    {
        $this->artisan('api:contract:audit')
            ->expectsOutputToContain('0 undocumented; 0 registry issue(s).')
            ->assertSuccessful();

        $this->artisan('api:contract:audit', ['--only-undocumented' => true])
            ->expectsOutputToContain('0 undocumented; 0 registry issue(s).')
            ->assertSuccessful();
    }

    public function test_audit_rejects_an_uncovered_runtime_route(): void
    {
        $registry = $this->registry();
        $registry['routes'] = [];

        $this->artisan('api:contract:audit', ['--exceptions' => $this->writeRegistry($registry)])
            ->expectsOutputToContain('undocumented')
            ->assertFailed();
    }

    public function test_audit_rejects_stale_and_redundant_exceptions(): void
    {
        $registry = $this->registry();
        $registry['routes'][] = [
            'method' => 'GET',
            'path' => '/api/v1/not-a-runtime-route',
            'reason' => 'Test stale entry.',
        ];
        $registry['routes'][] = [
            'method' => 'GET',
            'path' => '/api/v1/user/profile',
            'reason' => 'Test redundant entry.',
        ];

        $this->artisan('api:contract:audit', ['--exceptions' => $this->writeRegistry($registry)])
            ->expectsOutputToContain('does not match a registered route')
            ->expectsOutputToContain('unnecessarily excepted')
            ->assertFailed();
    }

    public function test_audit_rejects_duplicate_and_invalid_entries(): void
    {
        $duplicate = $this->registry();
        $duplicate['routes'][] = $duplicate['routes'][0];
        $this->artisan('api:contract:audit', ['--exceptions' => $this->writeRegistry($duplicate)])
            ->expectsOutputToContain('Duplicate runtime-only route')
            ->assertFailed();

        $invalid = $this->registry();
        $invalid['routes'][0]['method'] = 'INVALID';
        $this->artisan('api:contract:audit', ['--exceptions' => $this->writeRegistry($invalid)])
            ->expectsOutputToContain('Invalid runtime-only route')
            ->assertFailed();
    }

    private function registry(): array
    {
        return json_decode(
            File::get(base_path('openapi/runtime-only-routes.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function writeRegistry(array $registry): string
    {
        $relative = 'storage/framework/testing/runtime-only-'.uniqid().'.json';
        $absolute = base_path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $absolute;

        return $relative;
    }
}
