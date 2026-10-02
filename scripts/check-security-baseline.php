<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require __DIR__.'/../vendor/autoload.php';

$requirements = [
    'laravel/framework' => '13.30.0',
    'league/flysystem' => '3.35.3',
];

$failed = false;

foreach ($requirements as $package => $minimum) {
    if (! InstalledVersions::isInstalled($package)) {
        fwrite(STDERR, sprintf("ERROR: %s no está instalado; se requiere >= %s\n", $package, $minimum));
        $failed = true;

        continue;
    }

    $version = InstalledVersions::getVersion($package);

    if ($version === null || version_compare($version, $minimum, '<')) {
        fwrite(
            STDERR,
            sprintf(
                "ERROR: %s %s; se requiere >= %s\n",
                $package,
                $version ?? 'versión desconocida',
                $minimum,
            ),
        );
        $failed = true;

        continue;
    }

    printf("OK: %s %s >= %s\n", $package, $version, $minimum);
}

exit($failed ? 1 : 0);
