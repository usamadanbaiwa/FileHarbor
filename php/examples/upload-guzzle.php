<?php

declare(strict_types=1);

require getcwd() . '/vendor/autoload.php';

use GuzzleHttp\Client;

$base = getenv('FILEHARBOR_API_BASE');
$key = getenv('FILEHARBOR_API_KEY');

if ($base === false || $key === false || !isset($argv[1])) {
    throw new RuntimeException('Set FILEHARBOR_API_BASE and FILEHARBOR_API_KEY, then pass a file path.');
}

$filename = $argv[1];
$client = new Client([
    'base_uri' => rtrim($base, '/') . '/',
    'headers' => ['Authorization' => 'Bearer ' . $key],
    'connect_timeout' => 10,
    'timeout' => 60,
]);

$client->post('directories', ['json' => ['source' => 'assets']]);
$stream = fopen($filename, 'rb');

if ($stream === false) {
    throw new RuntimeException('Cannot open the upload file.');
}

try {
    $response = $client->post('upload', [
        'multipart' => [
            ['name' => 'folder', 'contents' => 'assets'],
            ['name' => 'file', 'contents' => $stream, 'filename' => basename($filename)],
        ],
    ]);

    echo (string) $response->getBody() . PHP_EOL;
} finally {
    if (is_resource($stream)) {
        fclose($stream);
    }
}
