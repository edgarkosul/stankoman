<?php

namespace App\Support;

/**
 * Ссылки на мессенджеры магазина — одни на шапку и подвал.
 *
 * Ссылка MAX раньше была зашита в шаблон как `https://max.ru/`. Это главная
 * мессенджера: она не открывает чат, а предлагает скачать приложение.
 * Чат открывает только ссылка на профиль (`https://max.ru/u/...`), а её знает
 * владелец, поэтому обе ссылки — настройки в админке.
 *
 * Пустой MAX — значка нет: ссылка в никуда хуже, чем её отсутствие.
 * Пустой Telegram — чат по номеру телефона компании, как было до настройки.
 */
final readonly class MessengerLinks
{
    public function __construct(
        public ?string $max,
        public ?string $telegram,
    ) {}

    public static function fromConfig(): self
    {
        $max = trim((string) config('company.max_url'));
        $telegram = trim((string) config('company.telegram_url'));
        $phoneDigits = preg_replace('/\D+/', '', (string) config('company.phone')) ?? '';

        if ($telegram === '' && $phoneDigits !== '') {
            $telegram = 'tg://resolve?phone='.$phoneDigits;
        }

        return new self(
            max: $max !== '' ? $max : null,
            telegram: $telegram !== '' ? $telegram : null,
        );
    }

    /**
     * Веб-ссылку открываем в новой вкладке, чтобы покупатель не уходил из магазина;
     * `tg://` сразу зовёт приложение, и новая вкладка от него осталась бы пустой.
     */
    public function telegramOpensInNewTab(): bool
    {
        return $this->telegram !== null && str_starts_with($this->telegram, 'https://');
    }
}
