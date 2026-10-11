<?php

namespace App\Shop\Chat;

use Illuminate\Support\Facades\Cookie;
use Siteko\Chatbot\Chat\Contracts\ConversationTokenStore;

/**
 * Токен разговора — в httpOnly-куке `intertooler_chat`, как было до пакета:
 * разговоры, начатые старым движком, открываются той же кукой.
 *
 * Кука, а не localStorage, как у виджета платформы: панель живёт в рамке
 * со своего же домена (`/chat/panel`), куку браузер шлёт, а токен даёт доступ
 * к переписке, где может оказаться телефон, — из localStorage его унёс бы
 * любой XSS. Вечная — потому что вернувшийся через месяц посетитель должен
 * увидеть свою историю; Safari режет до семи дней только те куки, что
 * выставлены из JS.
 *
 * Scoped: выданный токен нужен до конца того же запроса (кука уйдёт только
 * с ответом), а следующему запросу — уже из куки.
 */
final class CookieConversationToken implements ConversationTokenStore
{
    private ?string $issued = null;

    private bool $forgotten = false;

    public function __construct(private readonly string $cookieName) {}

    public function token(): ?string
    {
        if ($this->issued !== null) {
            return $this->issued;
        }

        if ($this->forgotten) {
            return null;
        }

        $token = request()->cookie($this->cookieName);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function remember(string $token): void
    {
        $this->issued = $token;
        $this->forgotten = false;

        Cookie::queue(Cookie::forever(
            name: $this->cookieName,
            value: $token,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /** «Очистить переписку» и выход из аккаунта: чужая переписка не достанется следующему. */
    public function forget(): void
    {
        $this->issued = null;
        $this->forgotten = true;

        Cookie::queue(Cookie::forget($this->cookieName));
    }
}
