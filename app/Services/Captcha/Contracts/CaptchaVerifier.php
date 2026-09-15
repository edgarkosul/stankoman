<?php

namespace App\Services\Captcha\Contracts;

/**
 * Проверка токена капчи на стороне сервера.
 *
 * Один метод и никакого состояния: провайдер отличается только тем, куда
 * уходит токен и как читается ответ. Всё остальное — нужна ли проверка
 * вообще, каким ключом рисовать виджет — знает CaptchaManager.
 */
interface CaptchaVerifier
{
    /** Пропустить посетителя дальше? */
    public function verify(string $token, ?string $ip = null): bool;
}
