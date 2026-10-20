<?php

// Disposable browser fixture server. Never reads .env or accepts a working database.
use App\Platform\Models\PlatformOperator;
use Database\Seeders\RolesSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$fixtureRoot = realpath($root.'/storage/framework/testing');
$fixture = getenv('PLATFORM_BROWSER_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! $fixtureRoot || ! is_string($fixture)
    || dirname($fixture) !== $fixtureRoot || ! preg_match('/^platform-panel-[a-f0-9-]{36}\.sqlite$/D', basename($fixture))) {
    http_response_code(503);
    exit('Disposable browser fixture required.');
}
if (is_file($root.'/bootstrap/cache/config.php')) {
    exit('Cached working configuration is not permitted.');
}
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/build/')) {
        $asset = realpath($root.'/public'.$path);
        if ($asset && str_starts_with($asset, realpath($root.'/public/build').DIRECTORY_SEPARATOR) && is_file($asset)) {
            return false;
        }
    }
    if (! str_starts_with($path, '/platform') && ! str_starts_with($path, '/api/v1/platform/')) {
        http_response_code(404);
        exit;
    }
}
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-testing');
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $fixture,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array',
    'mail.default' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null', 'hashing.bcrypt.rounds' => 4]);
Mail::fake();
// Deliberate post-commit publication failure; no mail or real queue is used.
Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Synthetic unavailable queue'));
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--prepare') {
    if (is_file($fixture)) {
        exit('Refusing to recreate an existing fixture.');
    }
    touch($fixture);
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);
    PlatformOperator::create(['name' => 'Operador sintético', 'email' => 'panel-browser@example.test', 'password' => 'Synthetic-panel-password-123',
        'verified_at' => now(), 'mfa_confirmed_at' => now(), 'totp_secret' => 'JBSWY3DPEHPK3PXP']);
    PlatformOperator::create(['name' => 'Operador para inscripción', 'email' => 'enroll-browser@example.test', 'password' => 'Synthetic-panel-password-123', 'verified_at' => now()]);
    echo "Disposable browser fixtures ready.\n";
    exit;
}
if (! is_file($fixture)) {
    http_response_code(503);
    exit('Fixture is absent.');
}
$app->handleRequest(Request::capture());
