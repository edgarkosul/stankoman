<?php

namespace App\Services\Captcha;

use App\Services\Captcha\Contracts\CaptchaVerifier;
use App\Services\Captcha\Verifiers\NullVerifier;
use App\Services\Captcha\Verifiers\SmartCaptchaVerifier;

/**
 * Единственное место, которое знает про капчу всё.
 *
 * У донора это знание сначала было размазано по трём слоям: разметка сама
 * читала конфиг и решала, рисовать ли виджет; компонент собирал правило
 * тернарником; провайдер чата считал признак включённости в третий раз и по
 * третьей формуле — и у чата условие оказалось строже остальных. Здесь ответ
 * один на всех: нужна ли проверка, каким ключом рисовать виджет, что отдать
 * в браузер и пропускать ли токен.
 *
 * Класс намеренно не про чат: в чате капча стоит первой, но формы витрины
 * (обратный звонок, запрос цены) закрываются той же проверкой, и второй
 * копии этого решения у них быть не должно.
 */
final class CaptchaManager
{
    private ?CaptchaVerifier $verifier = null;

    /**
     * @param  array<string, array<string, mixed>>  $drivers
     */
    public function __construct(
        private readonly bool $switchedOn,
        private readonly string $driver,
        private readonly array $drivers,
    ) {}

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Спрашиваем ли мы капчу прямо сейчас.
     *
     * Мало включить рубильник — нужны ещё и ключи. Без клиентского ключа
     * виджет в браузере не поднимется, и «включённая» проверка означала бы
     * форму, которую невозможно отправить; без серверного нечем проверить
     * токен. Поэтому неполная настройка — это выключенная капча, а не
     * закрытый магазин: вопросы покупателей дороже, а первым эшелоном против
     * ботов всё равно работают потолки частоты.
     */
    public function enabled(): bool
    {
        if (! $this->switchedOn || $this->driver === 'null') {
            return false;
        }

        return $this->siteKey() !== '' && $this->setting('secret_key', '') !== '';
    }

    /** Клиентская часть ключевой пары — та, что уходит в браузер. */
    public function siteKey(): string
    {
        return (string) $this->setting('site_key', '');
    }

    /**
     * Пропустить посетителя дальше?
     *
     * Выключенная капча пропускает всех и до сети не доходит — на этом
     * держится вся разработка на деве.
     */
    public function verify(string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return $this->verifier()->verify($token, $ip);
    }

    /**
     * Всё, что нужно разметке и JS, одним массивом.
     *
     * Разметка больше не читает конфиг и не решает сама — она получает
     * готовый ответ и передаёт его в Alpine как есть.
     *
     * @return array<string, mixed>
     */
    public function frontendConfig(): array
    {
        $enabled = $this->enabled();

        return [
            'enabled' => $enabled,
            // Ключ выключенной капчи в страницу не уезжает: секретом он не
            // является, но и работать там всё равно не будет, а для JS
            // пустой ключ означает «ничего не грузить».
            'siteKey' => $enabled ? $this->siteKey() : '',
            'jsUrl' => (string) $this->setting('js_url', ''),
            'invisible' => (bool) $this->setting('invisible', true),
            'hideShield' => (bool) $this->setting('hide_shield', true),
            'language' => (string) $this->setting('language', 'ru'),
            'test' => (bool) $this->setting('test', false),
        ];
    }

    /**
     * Показывать ли плашку об обработке данных.
     *
     * Условия использования SmartCaptcha разрешают убрать её угловой блок
     * только в обмен на собственное уведомление — то есть плашка не выбор
     * оформления, а обязательство.
     */
    public function showsNotice(): bool
    {
        return $this->enabled() && (bool) $this->setting('hide_shield', true);
    }

    private function verifier(): CaptchaVerifier
    {
        return $this->verifier ??= match ($this->driver) {
            'smartcaptcha' => new SmartCaptchaVerifier(
                secretKey: (string) $this->setting('secret_key', ''),
                validateUrl: (string) $this->setting('validate_url', ''),
                timeout: (float) $this->setting('timeout', 4.0),
            ),
            default => new NullVerifier,
        };
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->drivers[$this->driver][$key] ?? $default;
    }
}
