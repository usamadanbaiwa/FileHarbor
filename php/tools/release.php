<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/bootstrap.php';

$documentFolder = basename(\FileHarbor\Config\Config::documentRoot());
$version = '';

if (isset($argv[1])) {
    $version = $argv[1];
}

if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D', $version) !== 1) {
    fwrite(STDERR, 'Usage: php tools/release.php 0.1.0' . PHP_EOL);
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, 'The release builder requires the ZIP extension.' . PHP_EOL);
    exit(1);
}

$directory = $root . '/dist';

if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}

$filename = $directory . '/fileharbor-php-' . $version . '.zip';
$archive = new ZipArchive();
$result = $archive->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($result !== true) {
    throw new RuntimeException('Cannot create the release archive.');
}

$entries = [
    'src', 'config/config.example.php', 'tests', 'tools', 'docs', 'examples',
    'README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'composer.json', 'composer.lock', '.editorconfig', 'router.php',
];

function addReleaseEntry(ZipArchive $archive, string $root, string $relative, ?string $destination = null): void
{
    if ($destination === null) {
        $destination = $relative;
    }

    if ($relative === 'config/config.php') {
        return;
    }

    $filename = $root . '/' . $relative;

    if (is_link($filename)) {
        throw new RuntimeException('Release sources must not contain symbolic links: ' . $relative);
    }

    if (is_dir($filename)) {
        foreach (scandir($filename) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            addReleaseEntry($archive, $root, $relative . '/' . $name, $destination . '/' . $name);
        }

        return;
    }

    if (!$archive->addFile($filename, $destination)) {
        throw new RuntimeException('Cannot add release file: ' . $relative);
    }
}

try {
    // Release packages use the default folder name, even when built from a renamed installation.
    addReleaseEntry($archive, $root, $documentFolder, 'public');

    $license = $root . '/LICENSE';

    if (!file_exists($license)) {
        $license = dirname($root) . '/LICENSE';
    }

    if (!is_file($license) || is_link($license)) {
        throw new RuntimeException('The release requires a regular LICENSE file.');
    }

    if (!$archive->addFile($license, 'LICENSE')) {
        throw new RuntimeException('Cannot add the release license.');
    }

    foreach ($entries as $entry) {
        addReleaseEntry($archive, $root, $entry);
    }
} finally {
    $archive->close();
}

echo 'Created ' . $filename . PHP_EOL;
