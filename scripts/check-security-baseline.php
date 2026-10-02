<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$requirements = [
    'laravel/framework' => '13.30.0',
    'league/flysystem' => '3.35.3',
];

$failed = false;

foreach ($requirements as $package => $minimum) {
    $version = Composer\InstalledVersions::getVersion($package);

    if ($version === null) {
        fwrite(STDERR, sprintf("ERROR: %s no está instalado.\n", $package));
        $failed = true;
        continue;
    }

    if (version_compare($version, $minimum, '<')) {
        fwrite(
            STDERR,
            sprintf("ERROR: %s %s; se requiere >= %s.\n", $package, $version, $minimum),
        );
        $failed = true;
        continue;
    }

    printf("OK: %s %s >= %s\n", $package, $version, $minimum);
}

exit($failed ? 1 : 0);
