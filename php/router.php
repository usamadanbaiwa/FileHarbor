<?php

declare(strict_types=1);

// This router is for the local PHP development server only.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (in_array($path, ['/panel/style.css', '/panel/script.js'], true)) {
    return false;
}

require_once __DIR__ . '/src/bootstrap.php';

runFileHarbor();
