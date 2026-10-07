<?php

namespace App\Services\Ai\Bench;

use InvalidArgumentException;

/**
 * Вариант замера: модель и, через плюс, выбор провайдера у aitunnel —
 * `deepseek-v4.1-flash` или `deepseek-v4.1-flash+latency`.
 *
 * Вариант без плюса идёт БЕЗ объекта `provider`, что бы ни стояло в
 * AI_GATEWAY_PROVIDER_SORT: иначе «с latency против без» на проде, где
 * настройка уже включена, сравнивал бы latency с самим собой.
 */
final readonly class BenchVariant
{
    private const SORTS = ['latency', 'throughput', 'price'];

    public function __construct(
        public string $label,
        public string $model,
        public string $providerSort,
    ) {}

    public static function parse(string $label): self
    {
        $label = trim($label);
        [$model, $sort] = array_pad(explode('+', $label, 2), 2, '');

        if ($model === '' || ($sort !== '' && ! in_array($sort, self::SORTS, true))) {
            throw new InvalidArgumentException(
                "Вариант «{$label}»: нужна модель и, через плюс, один из ".implode(', ', self::SORTS).'.'
            );
        }

        return new self($label, $model, $sort);
    }
}
