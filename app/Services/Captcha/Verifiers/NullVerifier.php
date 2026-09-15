<?php

namespace App\Services\Captcha\Verifiers;

use App\Services\Captcha\Contracts\CaptchaVerifier;

/**
 * Заглушка: пропускает всех.
 *
 * Режим разработки и тестов. Ключи на деве есть, но капча заведена с
 * проверкой домена, и токен с localhost сервис не примет — то есть без
 * заглушки чат на деве оказался бы закрыт наглухо.
 *
 * До сети не доходит никогда.
 */
final class NullVerifier implements CaptchaVerifier
{
    public function verify(string $token, ?string $ip = null): bool
    {
        return true;
    }
}
