<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Siteko\Chatbot\Chat\Contracts\ConversationTokenStore;
use Siteko\Chatbot\Models\ChatConversation;

class ChatResumeController extends Controller
{
    public function __invoke(string $token, ConversationTokenStore $tokens): RedirectResponse
    {
        // Токен — 40 символов из алфавита Str::random. Всё, что не похоже,
        // до базы не доходит.
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        $conversation = ChatConversation::query()->byToken($token)->first();

        abort_if($conversation === null, 404);

        $tokens->remember($conversation->token);

        // ?chat=1 разворачивает панель сразу: человек пришёл читать ответ,
        // а не искать на витрине, куда нажать.
        return redirect()->route('home', ['chat' => 1]);
    }
}
