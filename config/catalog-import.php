<?php

return [
    'feed_upload' => [
        'max_size_kb' => (int) env('CATALOG_IMPORT_FEED_UPLOAD_MAX_SIZE_KB', 128 * 1024),
    ],
    'media' => [
        'queue' => env('CATALOG_IMPORT_MEDIA_QUEUE', 'default'),
        'disk' => env('CATALOG_IMPORT_MEDIA_DISK', 'public'),
        'storage_folder' => env('CATALOG_IMPORT_MEDIA_STORAGE_FOLDER', 'pics/import'),
        'timeout_seconds' => (int) env('CATALOG_IMPORT_MEDIA_TIMEOUT_SECONDS', 25),
        'retry_delays_ms' => [250, 750, 1500],
        'max_bytes' => (int) env('CATALOG_IMPORT_MEDIA_MAX_BYTES', 10 * 1024 * 1024),
        'recheck_ttl_seconds' => (int) env('CATALOG_IMPORT_MEDIA_RECHECK_TTL_SECONDS', 7 * 24 * 60 * 60),
        'use_conditional_headers_for_recheck' => (bool) env('CATALOG_IMPORT_MEDIA_USE_CONDITIONAL_HEADERS_FOR_RECHECK', true),
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
    'schedule' => [
        'enabled' => (bool) env('CATALOG_IMPORT_SCHEDULE_ENABLED', false),
        'timezone' => env('CATALOG_IMPORT_SCHEDULE_TIMEZONE', 'Europe/Moscow'),
        'vactool' => [
            'enabled' => (bool) env('CATALOG_IMPORT_SCHEDULE_VACTOOL_ENABLED', false),
            'time' => env('CATALOG_IMPORT_SCHEDULE_VACTOOL_TIME', '04:00'),
            'mode' => env('CATALOG_IMPORT_SCHEDULE_VACTOOL_MODE', 'partial_import'),
            'source' => env('CATALOG_IMPORT_SCHEDULE_VACTOOL_SOURCE', 'https://vactool.ru/sitemap.xml'),
            'download_images' => (bool) env('CATALOG_IMPORT_SCHEDULE_VACTOOL_DOWNLOAD_IMAGES', true),
            'skip_existing' => (bool) env('CATALOG_IMPORT_SCHEDULE_VACTOOL_SKIP_EXISTING', false),
        ],
        'metalmaster' => [
            'enabled' => (bool) env('CATALOG_IMPORT_SCHEDULE_METALMASTER_ENABLED', false),
            'time' => env('CATALOG_IMPORT_SCHEDULE_METALMASTER_TIME', '04:30'),
            'mode' => env('CATALOG_IMPORT_SCHEDULE_METALMASTER_MODE', 'partial_import'),
            'source' => env('CATALOG_IMPORT_SCHEDULE_METALMASTER_SOURCE', storage_path('app/parser/metalmaster-buckets.json')),
            'bucket' => env('CATALOG_IMPORT_SCHEDULE_METALMASTER_BUCKET', ''),
            'timeout' => (int) env('CATALOG_IMPORT_SCHEDULE_METALMASTER_TIMEOUT', 25),
            'download_images' => (bool) env('CATALOG_IMPORT_SCHEDULE_METALMASTER_DOWNLOAD_IMAGES', true),
            'skip_existing' => (bool) env('CATALOG_IMPORT_SCHEDULE_METALMASTER_SKIP_EXISTING', false),
        ],
    ],
];
