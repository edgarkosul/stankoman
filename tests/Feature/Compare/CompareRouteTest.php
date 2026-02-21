<?php

use App\Models\Product;

it('resolves compare route to compare page path', function (): void {
    expect(route('compare.index', [], false))->toBe('/compare');
});

it('renders empty compare page when session has no products', function (): void {
    $this->get(route('compare.index'))
        ->assertSuccessful()
        ->assertSee('Пока пусто. Добавляйте товары кнопкой «В сравнение».');
});

it('renders compared product from session ids', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар для сравнения',
        'slug' => 'test-compare-product',
        'is_active' => true,
        'price_amount' => 120000,
    ]);

    session()->put('compare.ids', [$product->id]);

    $this->get(route('compare.index'))
        ->assertSuccessful()
        ->assertSee('Сравнение')
        ->assertSee('Тестовый товар для сравнения');
});
