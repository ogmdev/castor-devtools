<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\CurrentCheckout;
use Ogmdev\CastorDevtools\CurrentProject;
use Ogmdev\CastorDevtools\Project;

afterEach(function (): void {
    CurrentProject::reset();
    CurrentCheckout::reset();

    foreach (TemporaryDirectories::$created as $directory) {
        removeDirectory($directory);
    }

    TemporaryDirectories::$created = [];
});

final class TemporaryDirectories
{
    /** @var list<string> */
    public static array $created = [];
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        @unlink($directory);

        return;
    }

    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        removeDirectory($directory.'/'.$entry);
    }

    rmdir($directory);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sampleProject(array $overrides = []): Project
{
    return new Project(...[
        'name' => 'acme',
        'domain' => 'acme.localhost',
        'subdomains' => ['app', 'www', 'vite'],
        'phpService' => 'php-app',
        'nginxServicesByPhpService' => ['php-app' => ['nginx'], 'php-api' => ['nginx-api']],
        'projectMountedServicePrefixes' => ['php-', 'test-php-'],
        ...$overrides,
    ]);
}

function temporaryDirectory(string $prefix): string
{
    $directory = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    TemporaryDirectories::$created[] = $directory;

    return $directory;
}
