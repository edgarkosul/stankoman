<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\Chat\ChatConversationService;
use Illuminate\Http\RedirectResponse;

/**
 * Возврат в переписку по ссылке из письма «менеджер ответил».
 *
 * Смысл маршрута — перенести разговор на другое устройство: писали
 * с рабочего компьютера, ответ читают с телефона. Кука там своя, поэтому
 * ссылка выдаёт новую — с тем же токеном разговора.
 *
 * Ссылка подписана и живёт неделю. Токен в ней и так секрет, но подпись
 * добавляет срок годности: письмо, забытое в почте на полгода, не должно
 * оставаться ключом к переписке.
 */
class ChatResumeController extends Controller
{
    public function __invoke(string $token, ChatConversationService $chat): RedirectResponse
    {
        // Токен — 40 символов из алфавита Str::random. Всё, что не похоже,
        // до базы не доходит.
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        $conversation = ChatConversation::query()->byToken($token)->first();

        abort_if($conversation === null, 404);

        $chat->rememberCookie($conversation);

        // ?chat=1 разворачивает панель сразу: человек пришёл читать ответ,
        // а не искать на витрине, куда нажать.
        return redirect()->route('home', ['chat' => 1]);
    }
}
