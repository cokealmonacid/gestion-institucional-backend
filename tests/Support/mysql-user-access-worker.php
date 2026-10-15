<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\UserAccess\InvitationService;
use App\Services\UserAccess\PasswordRecoveryService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap-mysql.php';

[$script, $operation, $payloadPath, $readyPath, $goPath, $startedPath, $outputPath] = $argv;
$payload = json_decode((string) file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-testing');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
config(['cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array']);

file_put_contents($readyPath, 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($goPath)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Timed out waiting for concurrency barrier.'.PHP_EOL);
        exit(2);
    }
    usleep(10_000);
}
file_put_contents($startedPath, 'started', LOCK_EX);

try {
    $result = match ($operation) {
        'activate' => [
            'completed' => $app->make(InvitationService::class)->activate($payload['credentials']),
        ],
        'reissue_invitation' => (static function () use ($app, $payload): array {
            $app->make(InvitationService::class)->reissue(
                User::query()->findOrFail($payload['actor_id']),
                $payload['user_id'],
            );

            return ['completed' => true];
        })(),
        'reset_password' => [
            'completed' => $app->make(PasswordRecoveryService::class)->reset($payload['credentials']),
        ],
        'issue_recovery' => (static function () use ($app, $payload): array {
            $app->make(PasswordRecoveryService::class)->request($payload['email']);

            return ['completed' => true];
        })(),
        'deactivate_user' => dispatchRequest($app, 'PATCH', '/api/v1/institution/users', $payload),
        'change_password' => dispatchRequest($app, 'PATCH', '/api/v1/user/update-password', $payload),
        default => throw new InvalidArgumentException("Unknown operation: {$operation}"),
    };
} catch (Throwable $exception) {
    $result = ['completed' => false, 'exception' => $exception::class];
}

file_put_contents($outputPath, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);

/** @return array{completed: bool, status: int} */
function dispatchRequest($app, string $method, string $uri, array $payload): array
{
    $token = $payload['_token'];
    unset($payload['_token']);

    $request = Request::create(
        $uri,
        $method,
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
    $response = $app->make(Kernel::class)->handle($request);

    return ['completed' => $response->isSuccessful(), 'status' => $response->getStatusCode()];
}
