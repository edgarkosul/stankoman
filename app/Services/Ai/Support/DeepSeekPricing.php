<?php

namespace App\Services\Ai\Support;

/**
 * Цена вызова API DeepSeek в рублях. В ответе DeepSeek цены нет, в отличие
 * от aitunnel (`usage.cost_rub`), — считаем по токенам.
 *
 * Тариф в долларах за миллион токенов, в часы пик вдвое дороже. Пик —
 * будни 01:00–04:00 и 06:00–10:00 UTC (api-docs.deepseek.com, 08.10.2026).
 * Китайские праздники вне пика, но их мы не знаем: в такие дни цена
 * завышена вдвое — ошибка в безопасную сторону.
 *
 * Рубли — по курсу, по которому пополнен баланс (ai_support.deepseek.usd_rub):
 * считается то, что ушло с нашего счёта, а не курс ЦБ.
 */
final class DeepSeekPricing
{
    /**
     * @param  array{hit: float, miss: float, output: float}  $peak  долларов за 1 млн токенов
     * @param  array{hit: float, miss: float, output: float}  $offPeak
     */
    public function __construct(
        private readonly array $peak,
        private readonly array $offPeak,
        private readonly float $usdRub,
    ) {}

    public static function fromConfig(): self
    {
        $prices = static fn (string $key): array => array_map('floatval', (array) config('ai_support.deepseek.prices.'.$key));

        return new self(
            peak: $prices('peak'),
            offPeak: $prices('off_peak'),
            usdRub: (float) config('ai_support.deepseek.usd_rub'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage  `usage` ответа DeepSeek
     * @param  float  $at  момент вызова, unix-время
     */
    public function rub(array $usage, float $at): float
    {
        $prices = self::isPeak($at) ? $this->peak : $this->offPeak;

        $hit = (int) ($usage['prompt_cache_hit_tokens'] ?? $usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $miss = (int) ($usage['prompt_cache_miss_tokens'] ?? max(0, (int) ($usage['prompt_tokens'] ?? 0) - $hit));
        $output = (int) ($usage['completion_tokens'] ?? 0);

        $usd = ($hit * $prices['hit'] + $miss * $prices['miss'] + $output * $prices['output']) / 1_000_000;

        return $usd * $this->usdRub;
    }

    public static function isPeak(float $at): bool
    {
        $weekday = (int) gmdate('N', (int) $at);
        $hour = (int) gmdate('G', (int) $at);

        return $weekday <= 5 && (($hour >= 1 && $hour < 4) || ($hour >= 6 && $hour < 10));
    }
}
