<?php

declare(strict_types=1);

namespace FileHarbor\Http;

use JsonException;
use stdClass;

final class Request
{
    public string $method;
    public string $path;
    public array $server;
    public array $query;
    public array $fields;
    public array $files;
    private ?array $json;

    public function __construct(
        array $server,
        array $query,
        array $fields,
        array $files,
        string $rawBody
    ) {
        $this->server = $server;
        $this->query = $query;
        $this->fields = $fields;
        $this->files = $files;
        $this->json = null;
        $this->method = 'GET';

        if (isset($server['REQUEST_METHOD'])) {
            $this->method = $server['REQUEST_METHOD'];
        }

        $uri = '/';

        if (isset($server['REQUEST_URI'])) {
            $uri = $server['REQUEST_URI'];
        }

        $path = parse_url($uri, PHP_URL_PATH);

        ApiError::require(is_string($path));

        $this->path = rawurldecode($path);


        if (str_starts_with(strtolower($this->header('content-type')), 'application/json')) {
            ApiError::require(
                strlen($rawBody) <= 16384,
                'FILE_TOO_LARGE',
                'The request exceeds the allowed size.',
                413
            );

            try {
                $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {

                throw new ApiError(400, 'INVALID_REQUEST', 'Invalid JSON.');
            }

            ApiError::require($decoded instanceof stdClass, 'INVALID_REQUEST', 'Expected a JSON object.');

            $this->json = (array) $decoded;
        }
    }

    public static function fromGlobals(): self
    {
        $rawBody = '';
        $contentType = '';

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $contentType = $_SERVER['CONTENT_TYPE'];
        }

        if (str_starts_with(strtolower($contentType), 'application/json')) {
            $stream = fopen('php://input', 'rb');

            if ($stream === false) {
                throw new ApiError(500, 'STORAGE_ERROR', 'Could not read the request.');
            }

        
            try {
                $read_stream = stream_get_contents($stream, 16385);
                // If empty string is returned, 
                if(empty($read_stream)) {
                    $rawBody = '{}';
                }
                else{
                    $rawBody = $read_stream;
                }

            } finally {
                fclose($stream);
            }

            ApiError::require(is_string($rawBody));
        }

        return new self($_SERVER, $_GET, $_POST, $_FILES, $rawBody);
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if ($name === 'content-type') {
            $key = 'CONTENT_TYPE';
        }

        if (isset($this->server[$key]) && is_string($this->server[$key])) {
            return $this->server[$key];
        }

        if ($name === 'authorization' && isset($this->server['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $this->server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return '';
    }

    public function json(): array
    {
        ApiError::require($this->json !== null, 'INVALID_REQUEST', 'Expected a JSON object.');

        return $this->json;
    }

    public static function field(array $input, string $name): mixed
    {
        if (array_key_exists($name, $input)) {
            return $input[$name];
        }

        return null;
    }

    public function listing(): array
    {
        $folder = '';
        $page = 1;
        $limit = 50;

        if (array_key_exists('folder', $this->query)) {
            $folder = $this->query['folder'];
        }

        if (array_key_exists('page', $this->query)) {
            $page = $this->query['page'];
        }

        if (array_key_exists('limit', $this->query)) {
            $limit = $this->query['limit'];
        }

        ApiError::require(
            is_scalar($page) && is_numeric($page) &&
            is_scalar($limit) && is_numeric($limit)
        );

        $pageNumber = (float) $page;
        $limitNumber = (float) $limit;

        ApiError::require(
            floor($pageNumber) === $pageNumber && $pageNumber >= 1 && $pageNumber <= 1000000 &&
            floor($limitNumber) === $limitNumber && $limitNumber >= 1 && $limitNumber <= 100
        );

        return [
            'folder' => $folder,
            'page' => (int) $pageNumber,
            'limit' => (int) $limitNumber,
        ];
    }
}
