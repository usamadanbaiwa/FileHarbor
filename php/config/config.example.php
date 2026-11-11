<?php

declare(strict_types=1);

return [
    'FILEHARBOR_DOCUMENT_ROOT' => 'public',
    'FILEHARBOR_ENV' => 'development',
    'FILEHARBOR_URL' => 'http://127.0.0.1:7241',
    'FILEHARBOR_SETUP_TOKEN' => '',
    'STORAGE_PATH' => dirname(__DIR__) . '/storage',
    'SERVE_FILES' => 'true',
    'PUBLIC_CACHE_MAX_AGE' => 86400,
    'FILEHARBOR_STATE_PATH' => dirname(__DIR__) . '/var',
    'MAX_FILE_SIZE_MB' => 25,
    'ALLOWED_MIME_TYPES' => 'image/jpeg,image/png,image/webp,application/pdf,video/mp4',
    'API_RATE_LIMIT' => 100,
    'API_RATE_WINDOW_SECONDS' => 60,
];
