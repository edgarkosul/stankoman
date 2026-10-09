<?php

namespace App\Services\Ai\Bench;

use App\Services\Ai\Providers\ChatChannels;
use InvalidArgumentException;

/**
 * Вариант замера. Два вида:
 *
 *   модель aitunnel и, через плюс, выбор провайдера — `deepseek-v4.1-flash`,
 *   `deepseek-v4.1-flash+latency` (`--models`);
 *
 *   канал чата — `deepseek` (сам API DeepSeek) или `aitunnel[+latency]`
 *   с моделью из настроек (`--channels`).
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
        /** Модель aitunnel; пусто — из настроек (вариант канала). */
        public string $model,
        public string $providerSort,
        /** Канал чата: aitunnel или deepseek (ChatChannels). */
        public string $channel = ChatChannels::AITUNNEL,
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

    /** `deepseek`, `aitunnel`, `aitunnel+latency`. */
    public static function channel(string $label): self
    {
        $label = trim($label);
        [$channel, $sort] = array_pad(explode('+', $label, 2), 2, '');

        $valid = match ($channel) {
            ChatChannels::AITUNNEL => $sort === '' || in_array($sort, self::SORTS, true),
            ChatChannels::DEEPSEEK => $sort === '',
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException(
                "Канал «{$label}»: deepseek или aitunnel, у aitunnel через плюс — один из ".implode(', ', self::SORTS).'.'
            );
        }

        return new self($label, '', $sort, $channel);
    }
}
