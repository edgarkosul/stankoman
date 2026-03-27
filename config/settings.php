<?php

$company = require __DIR__.'/company.php';

return [
    'general' => [
        'shop_name' => 'InterTooler.ru',
        'manager_emails' => array_filter(array_map('trim', explode(',', env('SHOP_MANAGER_EMAILS', '')))),
        'filament_admin_emails' => array_filter(array_map('trim', explode(',', env('FILAMENT_ADMIN_EMAILS', env('SHOP_MANAGER_EMAILS', ''))))),
    ],

    'company' => [
        'legal_name' => (string) ($company['legal_name'] ?? ''),
        'brand_line' => (string) ($company['brand_line'] ?? ''),
        'site_host' => (string) ($company['site_host'] ?? ''),
        'phone' => (string) ($company['phone'] ?? ''),
        'site_url' => (string) ($company['site_url'] ?? ''),
        'public_email' => (string) ($company['public_email'] ?? ''),
        'legal_addr' => (string) ($company['legal_addr'] ?? ''),
        'bank' => [
            'name' => (string) ($company['bank']['name'] ?? ''),
            'bik' => (string) ($company['bank']['bik'] ?? ''),
            'rs' => (string) ($company['bank']['rs'] ?? ''),
            'ks' => (string) ($company['bank']['ks'] ?? ''),
        ],
    ],

    'mail' => [
        'from' => [
            'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        ],
    ],

    'product' => [
        'stavka_nds' => 20,
    ],
];
