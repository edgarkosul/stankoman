<?php

namespace App\Shop\Chat;

use App\Services\Captcha\CaptchaManager;
use Siteko\Chatbot\Chat\Contracts\ChatCaptcha;

/**
 * Капча чата — та же, что у форм витрины: решение одно на весь магазин
 * (`CaptchaManager`). У донора три копии этого решения разошлись, и у чата
 * условие оказалось строже, чем у форм, без всякой на то причины.
 *
 * Когда её спрашивать (только первое сообщение разговора), решает движок.
 */
final class ShopChatCaptcha implements ChatCaptcha
{
    public function __construct(private readonly CaptchaManager $captcha) {}

    public function enabled(): bool
    {
        return $this->captcha->enabled();
    }

    /**
     * Ключ выключенной капчи в страницу не уезжает — как у `frontendConfig()`:
     * секретом он не является, но и работать там не будет.
     */
    public function siteKey(): ?string
    {
        $key = $this->captcha->enabled() ? $this->captcha->siteKey() : '';

        return $key !== '' ? $key : null;
    }

    /**
     * Пустой токен при включённой капче — отказ, а не исключение: его
     * присылает посетитель, у которого виджет не успел подняться.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->captcha->enabled()) {
            return true;
        }

        return $token !== null && $token !== '' && $this->captcha->verify($token, $ip);
    }

    public function widget(): array
    {
        $config = $this->captcha->frontendConfig();

        return array_filter([
            'js_url' => $config['jsUrl'] !== '' ? $config['jsUrl'] : null,
            'invisible' => $config['invisible'],
            'hide_shield' => $config['hideShield'],
            'language' => $config['language'],
            'test' => $config['test'],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
