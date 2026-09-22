<?php

declare(strict_types=1);

function assertSafeTestEnvironment(string $basePath, bool $loadPhpUnitEnvironment = false): void
{
    $fail = static function (string $message): never {
        fwrite(STDERR, "Unsafe test environment: {$message}".PHP_EOL);
        exit(1);
    };

    if (is_file($basePath.'/bootstrap/cache/config.php')) {
        $fail('bootstrap/cache/config.php exists. Run php artisan config:clear before running tests.');
    }

    if ($loadPhpUnitEnvironment) {
        $configuration = @simplexml_load_file($basePath.'/phpunit.xml');

        if ($configuration === false) {
            $fail('phpunit.xml could not be read.');
        }

        foreach ($configuration->php->env as $environment) {
            $name = (string) $environment['name'];

            if (getenv($name) === false) {
                $value = (string) $environment['value'];
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }

    $expected = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ];

    foreach ($expected as $name => $value) {
        if (getenv($name) !== $value) {
            $fail("{$name} must be {$value}.");
        }
    }
}

if (defined('PHPUNIT_COMPOSER_INSTALL')) {
    assertSafeTestEnvironment(dirname(__DIR__));
}
