<?php

declare(strict_types=1);

$fail = static function (string $message): never {
    fwrite(STDERR, "Unsafe MySQL concurrency test environment: {$message}".PHP_EOL);
    exit(1);
};

$basePath = dirname(__DIR__);

if (is_file($basePath.'/bootstrap/cache/config.php')) {
    $fail('bootstrap/cache/config.php exists. Run php artisan config:clear before running tests.');
}

$expected = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'acervo_user_access_concurrency_test',
    'MYSQL_CONCURRENCY_TESTS' => '1',
];

foreach ($expected as $name => $value) {
    if (getenv($name) !== $value) {
        $fail("{$name} must be {$value}.");
    }
}

if (! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    $fail('DB_HOST must be localhost or 127.0.0.1.');
}

if (getenv('DB_URL') !== '') {
    $fail('DB_URL must be explicitly empty so it cannot override the dedicated local database.');
}
