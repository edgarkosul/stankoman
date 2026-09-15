<?php

use App\Models\Product;
use App\Support\Search\ProductTextSearch;

/*
 * Витрина поверх общей точки входа. Драйвер в тестах — collection, пробы
 * по словам у него нет, поэтому она подменяется: здесь проверяется не
 * Meilisearch, а то, что страница честно говорит, без каких слов искала.
 */

beforeEach(function (): void {
    Product::query()->create([
        'name' => 'Drill Press 900',
        'slug' => 'drill-press-900',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 185000,
    ]);
});

it('показывает, без каких слов пришлось искать', function (): void {
    app()->instance(ProductTextSearch::class, new ProductTextSearch(
        wordHits: fn (array $words): array => array_map(fn (string $word): int => $word === 'drill' ? 1 : 0, $words),
        brands: fn (): array => [],
    ));

    $this->get(route('search', ['q' => 'hammerx drill']))
        ->assertSuccessful()
        ->assertSee('Drill Press 900')
        ->assertSee('Не нашлось: «hammerx».', false)
        ->assertSee('Показаны товары по остальным словам.');
});

it('когда нашлось по всем словам, подписи нет', function (): void {
    $this->get(route('search', ['q' => 'drill']))
        ->assertSuccessful()
        ->assertSee('Drill Press 900')
        ->assertDontSee('Не нашлось');
});

it('отдаёт ключи товаров одной строкой — так ищет ассистент', function (): void {
    $keys = app(ProductTextSearch::class)->keys('drill', 5);

    expect($keys->all())->toBe([Product::query()->value('id')]);
});
