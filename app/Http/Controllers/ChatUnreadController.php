<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Мне не ответили?» — вопрос свёрнутого чата.
 *
 * Существует потому, что скрытая панель сервер не опрашивает: `wire:poll`
 * в элементе с `display:none` не тикает, и это не недосмотр, а условие
 * задачи — виджет висит на каждой странице витрины. Но из-за этого ответ,
 * пришедший на открытой странице, посетитель не замечал бы вовсе: бейдж
 * считается при отрисовке.
 *
 * Отдельный контроллер, а не Livewire: тик стоит один запрос к базе
 * по индексированному токену и три поля JSON, без рендера компонента
 * и без снапшота. Спрашивают его редко — только те, у кого разговор
 * действительно ждёт ответа (см. `ChatPollingCadence::launcherWatches`).
 *
 * Ключ к переписке остаётся один — кука. Ни токена, ни идентификатора
 * разговора маршрут не принимает и наружу не отдаёт.
 */
class ChatUnreadController extends Controller
{
    public function __invoke(Request $request, ChatConversationService $chat): JsonResponse
    {
        return response()->json($chat->launcherState($request))
            // Ответ зависит от куки и меняется каждую минуту — кэшировать
            // его нельзя нигде, включая браузер и любой прокси перед нами.
            ->header('Cache-Control', 'no-store, private');
    }
}
