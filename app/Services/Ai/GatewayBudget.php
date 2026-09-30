<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Остаток бюджета на ключе шлюза — в рублях и в процентах.
 *
 * Настройка живёт не у нас, а в панели aitunnel, и это правильно: бюджет
 * должен ограничивать расход даже тогда, когда сломан наш код. Но видеть
 * его надо там же, где смотрят на расход бота, иначе «сколько осталось»
 * становится вопросом к разработчику.
 *
 * Ответ кэшируется: виджет рисуется при каждом открытии списка диалогов,
 * а цифра меняется медленно. Ошибка шлюза не должна ронять страницу —
 * при любой беде отдаём null, и виджет честно говорит, что цифры нет.
 */
final class GatewayBudget
{
    private const CACHE_KEY = 'ai:gateway:budget';

    /**
     * Сколько помнить, что шлюз не ответил. Меньше обычного срока: сбой
     * сети проходит сам, и цифра должна вернуться без ожидания в десять минут.
     */
    private const FAILURE_TTL = 60;

    public function __construct(
        private readonly int $ttl = 600,
    ) {}

    /**
     * @return array{remaining: float, initial: float, used: float, percent: float|null, interval: string}|null
     */
    public function snapshot(): ?array
    {
        $key = (string) config('ai_support.gateway.key');

        if ($key === '') {
            return null;
        }

        /*
         * Кэшируется и отсутствие цифры, а не только цифра. `Cache::remember`
         * null не запоминает вовсе, и у донора так и стоит: при незаданном
         * бюджете или лежащем шлюзе каждое открытие «Диалогов» заново ждало
         * восемь секунд таймаута — поймано на деве 15.09.2026, когда шлюз
         * не отвечал. Поэтому «нет цифры» лежит в кэше как false.
         */
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return is_array($cached) ? $cached : null;
        }

        [$snapshot, $ttl] = $this->fetch($key);

        Cache::put(self::CACHE_KEY, $snapshot ?? false, $ttl);

        return $snapshot;
    }

    /**
     * @return array{0: array{remaining: float, initial: float, used: float, percent: float|null, interval: string}|null, 1: int}
     */
    private function fetch(string $key): array
    {
        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(8)
                ->get(config('ai_support.gateway.base_url').'/aitunnel/key');
        } catch (Throwable $e) {
            Log::warning('gateway budget unavailable', ['error' => $e->getMessage()]);

            return [null, self::FAILURE_TTL];
        }

        if (! $response->successful()) {
            return [null, self::FAILURE_TTL];
        }

        $budget = $response->json('budget');

        // Бюджет на ключе не задан — это не ошибка, а состояние:
        // расход не ограничен ничем, и помнить это можно полный срок.
        if (! is_array($budget)) {
            return [null, $this->ttl];
        }

        $initial = (float) ($budget['initial'] ?? 0);
        $remaining = (float) ($budget['remaining'] ?? 0);

        return [[
            'remaining' => $remaining,
            'initial' => $initial,
            'used' => max(0.0, $initial - $remaining),
            'percent' => $initial > 0 ? round(($initial - $remaining) / $initial * 100) : null,
            'interval' => match ((string) ($budget['reset_interval'] ?? '')) {
                'daily' => 'сбрасывается каждый день',
                'weekly' => 'сбрасывается каждую неделю',
                'monthly' => 'сбрасывается каждый месяц',
                default => 'без автосброса',
            },
        ], $this->ttl];
    }

    /** Сбросить кэш — после смены ключа или ручной проверки. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
