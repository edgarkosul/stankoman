<?php

namespace App\Listeners\Chat;

use App\Services\Chat\ChatConversationService;
use Illuminate\Auth\Events\Login;

/**
 * Посетитель вошёл в аккаунт посреди разговора — привязываем диалог задним
 * числом.
 *
 * Разговор при этом НЕ переезжает: токен и переписка остаются те же.
 * Но от привязки зависят две вещи: менеджер видит, что за анонимом стоит
 * покупатель с историей заказов, а бот начинает называть цену со скидкой
 * для зарегистрированных — ту, что покупатель теперь видит на карточке.
 */
class AttachConversationToUser
{
    public function __construct(private readonly ChatConversationService $chat) {}

    public function handle(Login $event): void
    {
        $conversation = $this->chat->current();

        if ($conversation === null || $conversation->user_id !== null) {
            return;
        }

        $conversation->forceFill(['user_id' => $event->user->getAuthIdentifier()])->save();
    }
}
