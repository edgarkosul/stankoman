<?php

use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Kb\KbVectorStore;
use Tests\TestCase;

uses(TestCase::class);

it('переживает упаковку и распаковку вектора без потери смысла', function (): void {
    $vector = [0.1, -0.25, 0.5, 1.0, -1.0, 0.0];

    $back = KbVectorStore::unpackVector(KbVectorStore::packVector($vector));

    // float32 вместо float64: вдвое меньше места, потеря около 1e-7 —
    // на порядки ниже разницы между осмысленными оценками близости.
    expect($back)->toHaveCount(6);

    foreach ($vector as $i => $value) {
        expect($back[$i])->toBeGreaterThan($value - 1e-6)
            ->and($back[$i])->toBeLessThan($value + 1e-6);
    }
});

it('кладёт по четыре байта на число', function (): void {
    expect(strlen(KbVectorStore::packVector(array_fill(0, 1024, 0.5))))->toBe(4096);
});

it('приводит вектор к единичной длине, чтобы косинус стал скалярным произведением', function (): void {
    $store = new KbVectorStore(new FakeLlmClient, 'kb_chunks', 5);

    $unit = $store->normalize([3.0, 4.0]);

    expect($unit[0])->toBeGreaterThan(0.5999)->toBeLessThan(0.6001)
        ->and($unit[1])->toBeGreaterThan(0.7999)->toBeLessThan(0.8001);
});

it('не делит на ноль на пустом векторе', function (): void {
    $store = new KbVectorStore(new FakeLlmClient, 'kb_chunks', 5);

    expect($store->normalize([0.0, 0.0]))->toBe([0.0, 0.0]);
});

it('заглушка отдаёт одинаковый вектор на одинаковый текст', function (): void {
    $fake = new FakeLlmClient(64);

    $first = $fake->embed(['как оплатить'])->first();
    $second = $fake->embed(['как оплатить'])->first();

    // Иначе тест на инкрементную переиндексацию по content_hash
    // проверял бы случайность, а не логику.
    expect($first)->toBe($second)->and($first)->toHaveCount(64);
});

it('заглушка отдаёт нормированный вектор, как настоящие модели', function (): void {
    $vector = (new FakeLlmClient(128))->embed(['что угодно'])->first();

    $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

    expect($norm)->toBeGreaterThan(0.9999)->toBeLessThan(1.0001);
});

it('заглушка держит порядок текстов', function (): void {
    $batch = (new FakeLlmClient(32))->embed(['раз', 'два', 'три']);

    expect($batch->count())->toBe(3)
        ->and($batch->vectors[1])->toBe((new FakeLlmClient(32))->embed(['два'])->first());
});

it('отдаёт вектор вопроса нормированным — как и всё, что лежит в базе', function (): void {
    $store = new KbVectorStore(new FakeLlmClient(32), 'kb_chunks', 5);

    $vector = $store->embedQuery('как оплатить заказ');

    // Косинус считается скалярным произведением, поэтому единичная длина
    // здесь не аккуратность, а условие правильности оценок близости.
    $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

    expect($vector)->toHaveCount(32)
        ->and($norm)->toBeGreaterThan(0.9999)->toBeLessThan(1.0001);
});

it('не ходит на шлюз за пустым вопросом', function (): void {
    $store = new KbVectorStore(new FakeLlmClient(32), 'kb_chunks', 5);

    expect($store->embedQuery("  \n "))->toBe([]);
});
