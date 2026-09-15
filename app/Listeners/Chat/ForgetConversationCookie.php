<?php

namespace App\Listeners\Chat;

use App\Services\Chat\ChatConversationService;
use Illuminate\Auth\Events\Logout;

/**
 * Выход из аккаунта — сбрасываем куку.
 *
 * Токен диалога живёт в браузере годами, и на общем компьютере следующий
 * человек открыл бы чужую переписку — в которой может быть и телефон,
 * и содержание заказа. Сам диалог остаётся в базе: он привязан
 * к пользователю и найдётся в админке.
 */
class ForgetConversationCookie
{
    public function __construct(private readonly ChatConversationService $chat) {}

    public function handle(Logout $event): void
    {
        $this->chat->forgetCookie();
    }
}
