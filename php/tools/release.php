<?php

declare(strict_types=1);

$root = dirname(__DIR__);
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
    'src', 'public', 'config/config.example.php', 'tests', 'tools', 'docs', 'examples',
    'README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'composer.json', 'composer.lock', '.editorconfig', 'router.php',
];

function addReleaseEntry(ZipArchive $archive, string $root, string $relative): void
{
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

            addReleaseEntry($archive, $root, $relative . '/' . $name);
        }

        return;
    }

    if (!$archive->addFile($filename, $relative)) {
        throw new RuntimeException('Cannot add release file: ' . $relative);
    }
}

try {
    foreach ($entries as $entry) {
        addReleaseEntry($archive, $root, $entry);
    }
} finally {
    $archive->close();
}

echo 'Created ' . $filename . PHP_EOL;
