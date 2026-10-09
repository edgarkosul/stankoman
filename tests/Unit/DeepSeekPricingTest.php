<?php

use App\Services\Ai\Support\DeepSeekPricing;

/*
 * Цена вызова DeepSeek: её нет в ответе, считаем по токенам. Пик — будни
 * 01–04 и 06–10 UTC, вне пика вдвое дешевле. Перенесено из bots.
 */

function utcMoment(string $moment): float
{
    return (float) (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->getTimestamp();
}

it('часы пика — будни 01–04 и 06–10 UTC', function (string $moment, bool $peak): void {
    expect(DeepSeekPricing::isPeak(utcMoment($moment)))->toBe($peak);
})->with([
    'среда 00:59' => ['2026-10-07 00:59:59', false],
    'среда 01:00' => ['2026-10-07 01:00:00', true],
    'среда 03:59' => ['2026-10-07 03:59:59', true],
    'среда 04:00' => ['2026-10-07 04:00:00', false],
    'среда 05:59' => ['2026-10-07 05:59:59', false],
    'среда 06:00' => ['2026-10-07 06:00:00', true],
    'среда 09:59' => ['2026-10-07 09:59:59', true],
    'среда 10:00' => ['2026-10-07 10:00:00', false],
    'пятница 08:00' => ['2026-10-09 08:00:00', true],
    'суббота 08:00' => ['2026-10-10 08:00:00', false],
    'воскресенье 02:00' => ['2026-10-11 02:00:00', false],
]);

it('кэш, ввод и вывод — каждый по своей цене, в рублях по курсу', function (): void {
    $pricing = new DeepSeekPricing(
        peak: ['hit' => 0.006, 'miss' => 0.30, 'output' => 1.20],
        offPeak: ['hit' => 0.003, 'miss' => 0.15, 'output' => 0.60],
        usdRub: 90.0,
    );

    // Живой ответ bots с прода 08.10.2026: 256 из кэша, 163 мимо, 27 вывода.
    $usage = ['prompt_tokens' => 419, 'prompt_cache_hit_tokens' => 256, 'prompt_cache_miss_tokens' => 163, 'completion_tokens' => 27];

    expect($pricing->rub($usage, utcMoment('2026-10-07 08:00:00')))
        ->toEqualWithDelta((256 * 0.006 + 163 * 0.30 + 27 * 1.20) / 1e6 * 90, 1e-9)
        ->and($pricing->rub($usage, utcMoment('2026-10-07 12:00:00')))
        ->toEqualWithDelta((256 * 0.003 + 163 * 0.15 + 27 * 0.60) / 1e6 * 90, 1e-9);
});

it('без полей DeepSeek — по cached_tokens и prompt_tokens', function (): void {
    $pricing = new DeepSeekPricing(
        peak: ['hit' => 1.0, 'miss' => 10.0, 'output' => 100.0],
        offPeak: ['hit' => 1.0, 'miss' => 10.0, 'output' => 100.0],
        usdRub: 1.0,
    );

    $usage = ['prompt_tokens' => 1000, 'prompt_tokens_details' => ['cached_tokens' => 400], 'completion_tokens' => 10];

    expect($pricing->rub($usage, utcMoment('2026-10-10 12:00:00')))->toEqualWithDelta((400 * 1 + 600 * 10 + 10 * 100) / 1e6, 1e-12);
});
