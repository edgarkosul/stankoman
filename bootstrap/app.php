<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\TestingAccessGate;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Webhook бота MAX приходит снаружи и токена сессии не имеет.
         * Отдельного файла api-маршрутов в проекте нет, поэтому маршрут
         * живёт в web.php, а проверка CSRF снимается ровно с него.
         * Подлинность запроса подтверждает секрет в заголовке —
         * см. MaxHookController.
         */
        $middleware->validateCsrfTokens(except: ['hooks/max']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
