<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/support.php';

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ob_start();

$passed = 0;
$failed = 0;
$skipped = 0;
$suites = ['config.php', 'security.php', 'authentication.php', 'storage.php'];

if (extension_loaded('curl') && function_exists('proc_open')) {
    $suites[] = 'http.php';
} else {
    $skipped += 1;
    echo 'SKIP: HTTP tests require the cURL extension and proc_open.' . PHP_EOL;
}

foreach ($suites as $filename) {
    $tests = require __DIR__ . '/' . $filename;

    foreach ($tests as $description => $operation) {
        try {
            $operation();
            $passed += 1;

            echo 'PASS: ' . $description . PHP_EOL;
        } catch (Throwable $error) {
            $failed += 1;

            echo 'FAIL: ' . $description . PHP_EOL;
            echo '  ' . $error->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed, ' . $skipped . ' skipped.' . PHP_EOL;

restore_error_handler();
ob_end_flush();

if ($failed > 0) {
    exit(1);
}

exit(0);
