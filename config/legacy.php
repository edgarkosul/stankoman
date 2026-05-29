<?php

return [
    'kraton' => [
        'source_site' => env('LEGACY_KRATON_SOURCE_SITE', 'kratonkuban.ru'),
        'redirect_base_url' => env('LEGACY_KRATON_REDIRECT_BASE_URL', 'https://intertooler.ru'),
        'redirect_status' => (int) env('LEGACY_KRATON_REDIRECT_STATUS', 302),
    ],
];
