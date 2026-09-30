<?php

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Kb\KbVectorStore;

/*
 * Настоящий шлюз aitunnel. В обычный прогон не входит (phpunit.xml исключает
 * группу live), запуск — php artisan test --group=live. Ключ — AI_GATEWAY_KEY
 * из .env, прогон стоит доли копейки.
 *
 * Здесь проверяется то, чего заглушка не умеет в принципе: что модель отдаёт
 * заявленную размерность и что близкое по смыслу действительно ближе.
 */

beforeEach(function (): void {
    if (blank(config('ai_support.gateway.key'))) {
        $this->markTestSkipped('AI_GATEWAY_KEY не задан — живой шлюз проверять нечем.');
    }

    config(['ai_support.embedding.fake' => false]);
    app()->forgetInstance(LlmClient::class);
});

it('отдаёт единичные векторы заявленной размерности', function (): void {
    $batch = app(LlmClient::class)->embed(['как оплатить заказ по счёту', 'доставка по России']);

    expect($batch->count())->toBe(2)
        ->and($batch->model)->toBe(config('ai_support.embedding.model'));

    foreach ($batch->vectors as $vector) {
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        expect($vector)->toHaveCount((int) config('ai_support.embedding.dimensions'))
            ->and($norm)->toBeGreaterThan(0.99)->toBeLessThan(1.01);
    }
})->group('live');

it('ставит близкий по смыслу фрагмент выше постороннего', function (): void {
    $llm = app(LlmClient::class);
    $store = new KbVectorStore($llm, 'kb_chunks', 5);

    $question = $store->embedQuery('как оплатить заказ по счёту для организации');
    [$payment, $saws] = array_map(
        static fn (array $vector): array => $store->normalize($vector),
        $llm->embed([
            'Оплата: безналичным переводом на расчётный счёт продавца',
            'Ленточные пилы по дереву шириной от 6 до 35 мм',
        ])->vectors,
    );

    $dot = static fn (array $a, array $b): float => array_sum(array_map(static fn (float $x, float $y): float => $x * $y, $a, $b));

    expect($dot($question, $payment))->toBeGreaterThan($dot($question, $saws));
})->group('live');
