<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Siteko\Chatbot\Chat\ChatConversationService;

class ChatUnreadController extends Controller
{
    /**
     * Сколько непрочитанного и надо ли сторожить ответ — для лаунчера, пока
     * панель свёрнута. Разговор — по куке `intertooler_chat` (токен знает
     * CookieConversationToken), ни id, ни что-либо ещё от клиента не берём.
     */
    public function __invoke(ChatConversationService $chat): JsonResponse
    {
        return response()->json($chat->launcherState())
            // Ответ зависит от куки и меняется каждую минуту — кэшировать
            // его нельзя нигде, включая браузер и любой прокси перед нами.
            ->header('Cache-Control', 'no-store, private');
    }
}
