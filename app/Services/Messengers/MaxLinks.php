<?php

namespace App\Services\Messengers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Одноразовые коды подключения чата MAX.
 *
 * Кнопка в админке открывает бота ссылкой с кодом, MAX присылает код
 * обратно вместе с номером чата — так мы узнаём, чей это чат, и никто
 * не вписывает номер руками. Код живёт полчаса и гаснет после первого
 * использования: переслал ссылку коллеге — подключится тот, кто нажал
 * первым, а не оба. Без кода бот никого не подключает: иначе любой,
 * кто нашёл бота в поиске, получал бы заказы с телефонами покупателей.
 */
final class MaxLinks
{
    public const TTL_MINUTES = 30;

    public function issue(): string
    {
        // Только буквы и цифры: спецсимволы MAX в payload не пропускает.
        $code = Str::random(24);

        Cache::put(self::key($code), true, now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    public function consume(string $code): bool
    {
        if (preg_match('/^[A-Za-z0-9]{24}$/', $code) !== 1) {
            return false;
        }

        return Cache::pull(self::key($code)) === true;
    }

    private static function key(string $code): string
    {
        return 'max:link:'.$code;
    }
}
