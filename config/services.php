<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'dadata' => [
        'token' => env('DADATA_TOKEN'),
        'secret' => env('DADATA_SECRET'),
        'base' => env('DADATA_BASE', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs'),
        'timeout' => (float) env('DADATA_TIMEOUT', 4.0),
        'cache_ttl' => (int) env('DADATA_CACHE_TTL', 86400),
    ],

    'yandex_metrika' => [
        'id' => env('YANDEX_METRIKA_ID', '108565390'),
    ],

    /*
     * Бот магазина в MAX: присылает менеджерам заказы, заявки на звонок
     * и вопросы из чата. Обычный HTTP-клиент: официальный SDK существует
     * только на JS, а нам нужны три запроса.
     *
     * Токен выдают в кабинете партнёра MAX — бота может завести только
     * проверенная организация, и это НЕ тот токен, что раньше давал
     * @MasterBot. `bot_link` — полный адрес бота `https://max.ru/<username>`,
     * username отдаёт `GET /me`.
     *
     * Номер чата в конфиге больше не живёт: чаты менеджеров подключаются
     * кнопкой в админке («Продажи» → «Уведомления в MAX»), а webhook
     * регистрируется командой `php artisan max:hook` после выкладки.
     *
     * Не настроен — канал молча выключен: уведомление в админке и письмо
     * доходят и без него, а падать из-за необязательного пуша незачем.
     */
    'max' => [
        'base_url' => rtrim((string) env('MAX_API_BASE_URL', 'https://platform-api.max.ru'), '/'),
        'token' => env('MAX_BOT_TOKEN'),
        'bot_link' => env('MAX_BOT_LINK'),
        'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
        'timeout' => max(1, (int) env('MAX_API_TIMEOUT', 8)),
    ],

];
