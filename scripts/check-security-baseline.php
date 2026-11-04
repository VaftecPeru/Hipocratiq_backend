<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require dirname(__DIR__).'/vendor/autoload.php';

$requirements = [
    'laravel/framework' => '13.30.0',
    'league/flysystem' => '3.35.3',
    // #116 / #127: minimum fixed versions; never waive Composer advisories.
    'phpoffice/phpspreadsheet' => '5.10.0',
    'symfony/mime' => '8.1.10',
    // Symfony security advisories published on 2026-11-04 require >=8.1.11.
    'symfony/filesystem' => '8.1.11',
    'symfony/http-foundation' => '8.1.11',
    'symfony/http-kernel' => '8.1.11',
    'symfony/routing' => '8.1.11',
    'symfony/yaml' => '8.1.11',
];

$failed = false;

foreach ($requirements as $package => $minimum) {
    if (! InstalledVersions::isInstalled($package)) {
        fwrite(STDERR, sprintf("ERROR: %s no está instalado.\n", $package));
        $failed = true;

        continue;
    }

    $normalizedVersion = InstalledVersions::getVersion($package);
    $displayVersion = InstalledVersions::getPrettyVersion($package) ?? $normalizedVersion ?? 'desconocida';

    if ($normalizedVersion === null || version_compare($normalizedVersion, $minimum, '<')) {
        fwrite(
            STDERR,
            sprintf(
                "ERROR: %s %s; se requiere >= %s.\n",
                $package,
                $displayVersion,
                $minimum,
            ),
        );
        $failed = true;

        continue;
    }

    printf("OK: %s %s >= %s\n", $package, $displayVersion, $minimum);
}

exit($failed ? 1 : 0);
