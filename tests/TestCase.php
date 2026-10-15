<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-testing');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        return $app;
    }

    protected function authenticateInstitutionUser(User $user, array $abilities = [], string $guard = 'sanctum'): User
    {
        Sanctum::actingAs($user, $abilities, $guard);

        // Test-injected guard principal has no persisted Bearer credential. Use Sanctum's
        // session token rather than its PAT mock, which cannot satisfy revocation checks.
        return $user->withAccessToken(new TransientToken);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['documents.storage_disk' => null]);

        $this->withoutVite();
    }
}
