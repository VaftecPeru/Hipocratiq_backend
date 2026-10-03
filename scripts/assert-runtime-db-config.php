<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$actual = [
    'host' => (string) config('database.connections.pgsql.host'),
    'port' => (string) config('database.connections.pgsql.port'),
    'database' => (string) config('database.connections.pgsql.database'),
];

$expected = [
    'host' => (string) getenv('DB_HOST'),
    'port' => (string) getenv('DB_PORT'),
    'database' => (string) getenv('DB_DATABASE'),
];

foreach ($expected as $key => $value) {
    if ($value === '' || $actual[$key] !== $value) {
        fwrite(
            STDERR,
            sprintf(
                "ERROR: runtime DB config mismatch for %s: actual=%s expected=%s%s",
                $key,
                $actual[$key],
                $value === '' ? '<empty>' : $value,
                PHP_EOL,
            ),
        );

        exit(1);
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Runtime DB config OK: %s:%s/%s%s",
        $actual['host'],
        $actual['port'],
        $actual['database'],
        PHP_EOL,
    ),
);
