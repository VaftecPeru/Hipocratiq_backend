<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$requirements = [
    'laravel/framework' => '13.30.0',
    'league/flysystem' => '3.35.3',
];

foreach ($requirements as $package => $minimum) {
    $version = Composer\InstalledVersions::getVersion($package);

    if ($version === null || version_compare($version, $minimum, '<')) {
        fwrite(
            STDERR,
            sprintf(
                "ERROR: %s %s; se requiere >= %s\n",
                $package,
                $version ?? 'no instalado',
                $minimum,
            ),
        );

        exit(1);
    }

    printf("OK: %s %s >= %s\n", $package, $version, $minimum);
}
