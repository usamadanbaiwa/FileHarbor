<?php

declare(strict_types=1);

use FileHarbor\Config\Config;
use FileHarbor\Security\Credentials;

final class HttpTestServer
{
    public string $origin;
    public Config $config;
    private mixed $process;
    private string $cookie = '';
    private string $csrf = '';

    public function __construct(Config $config)
    {
        $this->config = $config;
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($listener === false) {
            throw new RuntimeException('Cannot reserve a test port.');
        }

        $address = stream_socket_get_name($listener, false);
        fclose($listener);

        $port = substr($address, strrpos($address, ':') + 1);
        $this->origin = 'http://127.0.0.1:' . $port;
        $root = dirname(__DIR__);
        $documentFolder = basename($config->documentRoot);

        if ($documentFolder !== 'public') {
            $root = dirname($config->state) . '/application';

            mkdir($root);
            $this->copyDirectory(dirname(__DIR__) . '/src', $root . '/src');
            $this->copyDirectory(dirname(__DIR__) . '/public', $root . '/' . $documentFolder);
            copy(dirname(__DIR__) . '/router.php', $root . '/router.php');
        }

        $environment = array_merge(getenv(), [
            'FILEHARBOR_ENV' => 'development',
            'FILEHARBOR_DOCUMENT_ROOT' => $documentFolder,
            'FILEHARBOR_URL' => $this->origin,
            'FILEHARBOR_SETUP_TOKEN' => $config->setupToken,
            'FILEHARBOR_STATE_PATH' => $config->state,
            'STORAGE_PATH' => $config->storage,
            'SERVE_FILES' => 'false',
            'PUBLIC_CACHE_MAX_AGE' => (string) $config->cacheMaxAge,
            'MAX_FILE_SIZE_MB' => (string) ($config->maxBytes / 1024 / 1024),
            'ALLOWED_MIME_TYPES' => implode(',', $config->allowed),
            'MIME_TYPE_EXTENSIONS' => json_encode((object) $config->formats, JSON_THROW_ON_ERROR),
            'API_RATE_LIMIT' => '100',
            'API_RATE_WINDOW_SECONDS' => '60',
        ]);

        if ($config->serveFiles) {
            $environment['SERVE_FILES'] = 'true';
        }

        $log = dirname($config->state) . '/server.log';

        $this->process = proc_open([
            PHP_BINARY,
            '-d', 'upload_max_filesize=26M',
            '-d', 'post_max_size=27M',
            '-d', 'display_errors=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $root . '/' . $documentFolder,
            $root . '/router.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, $root, $environment, ['bypass_shell' => true]);

        if (!is_resource($this->process)) {
            throw new RuntimeException('Cannot start the test server.');
        }

        fclose($pipes[0]);

        try {
            for ($attempt = 0; $attempt < 100; $attempt += 1) {
                try {
                    $health = $this->request('/api/health');

                    if ($health['status'] === 200) {
                        return;
                    }
                } catch (Throwable $error) {
                    // The listener may not be accepting connections yet.
                }

                $status = proc_get_status($this->process);

                if (!$status['running']) {
                    break;
                }

                usleep(30000);
            }

            throw new RuntimeException('Test server did not start. ' . file_get_contents($log));
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination);

        foreach (scandir($source) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (is_dir($source . '/' . $name)) {
                $this->copyDirectory($source . '/' . $name, $destination . '/' . $name);
            } else {
                copy($source . '/' . $name, $destination . '/' . $name);
            }
        }
    }

    public function request(string $path, array $options = []): array
    {
        $method = 'GET';
        $headers = ['Expect' => '', 'Connection' => 'close'];
        $body = null;

        if (isset($options['method'])) {
            $method = $options['method'];
        }

        if (isset($options['admin']) && $options['admin']) {
            $headers['Cookie'] = $this->cookie;
            $headers['Origin'] = $this->origin;
            $headers['X-CSRF-Token'] = $this->csrf;
        }

        if (array_key_exists('data', $options)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode((object) $options['data'], JSON_THROW_ON_ERROR);
        }

        if (isset($options['form'])) {
            $body = $options['form'];
        }

        if (isset($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $cookie = '';
        $responseHeaders = [];
        $connection = curl_init($this->origin . $path);

        curl_setopt_array($connection, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$cookie, &$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                if (str_starts_with(strtolower($line), 'set-cookie:')) {
                    $value = trim(substr($line, strlen('set-cookie:')));
                    $cookie = explode(';', $value)[0];
                }

                return strlen($line);
            },
        ]);

        if ($method === 'HEAD') {
            curl_setopt($connection, CURLOPT_NOBODY, true);
        }

        if ($body !== null) {
            curl_setopt($connection, CURLOPT_POSTFIELDS, $body);
        }

        try {
            $result = curl_exec($connection);

            if ($result === false) {
                throw new RuntimeException('HTTP test failed: ' . curl_error($connection));
            }

            $status = curl_getinfo($connection, CURLINFO_RESPONSE_CODE);
            $body = $result;

            if (!isset($options['raw']) || !$options['raw']) {
                $body = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            }

            return [
                'status' => $status,
                'body' => $body,
                'cookie' => $cookie,
                'headers' => $responseHeaders,
            ];
        } finally {
            unset($connection);
        }
    }

    public function setup(): array
    {
        return $this->request('/panel-api/setup', [
            'method' => 'POST',
            'data' => [
                'setup_token' => $this->config->setupToken,
                'username' => 'admin',
                'password' => 'test-only-password-123',
            ],
        ]);
    }

    public function login(): array
    {
        $result = $this->request('/panel-api/login', [
            'method' => 'POST',
            'headers' => ['Origin' => $this->origin],
            'data' => ['username' => 'admin', 'password' => 'test-only-password-123'],
        ]);

        $this->cookie = $result['cookie'];
        $this->csrf = '';

        if (isset($result['body']['data']['csrf_token'])) {
            $this->csrf = $result['body']['data']['csrf_token'];
        }

        return $result;
    }

    public function account(array $permissions = ['files:upload', 'files:list', 'files:delete', 'directories:create', 'directories:list', 'directories:delete']): array
    {
        $result = $this->request('/panel-api/accounts', [
            'method' => 'POST',
            'admin' => true,
            'data' => ['name' => 'Test account', 'permissions' => $permissions],
        ]);

        expectSame(201, $result['status']);

        $account = $result['body']['data'];
        $account['route'] = '/fileharbor/' . $account['id'] . '/api';
        $account['headers'] = ['Authorization' => 'Bearer ' . $account['api_key']];

        return $account;
    }

    public function upload(array $account, string $filename, string $path, array $fields = []): array
    {
        $fields['file'] = new CURLFile($path, 'application/octet-stream', $filename);

        return $this->request($account['route'] . '/upload', [
            'method' => 'POST',
            'headers' => $account['headers'],
            'form' => $fields,
        ]);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
    }
}

function withHttpServer(callable $operation, array $settings = []): void
{
    $base = dirname(__DIR__) . '/var/tests';

    if (!is_dir($base)) {
        mkdir($base, 0700, true);
    }

    $root = $base . '/test-' . Credentials::secret();

    mkdir($root, 0700);

    $config = Config::load(array_merge([
        'FILEHARBOR_ENV' => 'development',
        'FILEHARBOR_URL' => 'http://127.0.0.1:8080',
        'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
        'STORAGE_PATH' => $root . '/storage',
        'FILEHARBOR_STATE_PATH' => $root . '/state',
    ], $settings));

    try {
        $server = new HttpTestServer($config);

        try {
            $operation($server);
        } finally {
            $server->close();
        }
    } finally {
        removeFixture($root, $base);
    }
}
