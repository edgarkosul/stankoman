<?php

return [
    'kraton' => [
        'source_site' => env('LEGACY_KRATON_SOURCE_SITE', 'kratonkuban.ru'),
        'redirect_base_url' => env('LEGACY_KRATON_REDIRECT_BASE_URL', 'https://intertooler.ru'),
        'redirect_status' => (int) env('LEGACY_KRATON_REDIRECT_STATUS', 302),
        'allowed_match_strategies' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('LEGACY_KRATON_ALLOWED_MATCH_STRATEGIES', '')))
        )),
    ],
];
