<?php

return [
    'general' => [
        'shop_name' => 'Stankoman.ru',
        'notification_email' => 'admin@stankoman.ru',
        'manager_emails' => array_filter(array_map('trim', explode(',', env('SHOP_MANAGER_EMAILS', '')))),
        'filament_admin_emails' => array_filter(array_map('trim', explode(',', env('FILAMENT_ADMIN_EMAILS', env('SHOP_MANAGER_EMAILS', ''))))),
    ],

    'product' => [
        'stavka_nds' => 20,
    ],
];
