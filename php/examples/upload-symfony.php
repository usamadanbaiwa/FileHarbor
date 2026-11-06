<?php

declare(strict_types=1);

require getcwd() . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

$base = getenv('FILEHARBOR_API_BASE');
$key = getenv('FILEHARBOR_API_KEY');

if ($base === false || $key === false || !isset($argv[1])) {
    throw new RuntimeException('Set FILEHARBOR_API_BASE and FILEHARBOR_API_KEY, then pass a file path.');
}

$client = HttpClient::create([
    'headers' => ['Authorization' => 'Bearer ' . $key],
    'timeout' => 60,
]);

$client->request('POST', rtrim($base, '/') . '/directories', [
    'json' => ['source' => 'assets'],
])->getContent();

$form = new FormDataPart([
    'folder' => 'assets',
    'file' => DataPart::fromPath($argv[1]),
]);

$response = $client->request('POST', rtrim($base, '/') . '/upload', [
    'headers' => $form->getPreparedHeaders()->toArray(),
    'body' => $form->bodyToIterable(),
]);

echo $response->getContent() . PHP_EOL;
