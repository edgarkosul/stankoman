<?php

namespace App\Http\Middleware;

use App\Services\Chat\OperatorPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Кто-то из магазина сейчас за столом» — по факту работы в админке.
 *
 * Сигнал бесплатный: человек и так открывает страницы, и от него не
 * требуется ничего помнить. От него зависит, предложит ли чат позвать
 * менеджера в субботу, когда по режиму работы выходной, а кто-то всё равно
 * разбирает заказы.
 *
 * Считаются ТОЛЬКО переходы по страницам панели — не тики опроса.
 * Разница принципиальная: вкладка, забытая открытой на ночь, опрашивает
 * сервер до утра (уведомления, лента диалога) и держала бы «менеджер на
 * связи» в четыре часа ночи. Присутствие должно гаснуть само, когда человек
 * ушёл, а не когда он закрыл браузер.
 */
class TrackOperatorActivity
{
    public function __construct(private readonly OperatorPresence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Только обычные переходы: XHR-и Livewire и скачивание файлов
        // о присутствии человека не говорят.
        if ($request->isMethod('GET') && ! $request->ajax()) {
            $this->presence->touchActivity();
        }

        return $next($request);
    }
}
