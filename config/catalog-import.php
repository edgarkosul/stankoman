<?php

return [
    'media' => [
        'queue' => env('CATALOG_IMPORT_MEDIA_QUEUE', 'default'),
        'disk' => env('CATALOG_IMPORT_MEDIA_DISK', 'public'),
        'storage_folder' => env('CATALOG_IMPORT_MEDIA_STORAGE_FOLDER', 'pics/import'),
        'timeout_seconds' => (int) env('CATALOG_IMPORT_MEDIA_TIMEOUT_SECONDS', 25),
        'retry_delays_ms' => [250, 750, 1500],
        'max_bytes' => (int) env('CATALOG_IMPORT_MEDIA_MAX_BYTES', 10 * 1024 * 1024),
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/svg+xml',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ],
    ],
];
