<?php

use App\Models\Product;

it('resolves search route path', function (): void {
    expect(route('search', [], false))->toBe('/search');
});

it('renders hint for too short query', function (): void {
    $this->get(route('search', ['q' => 'a']))
        ->assertSuccessful()
        ->assertSee('Введите минимум 2 символа для поиска.');
});

it('renders found products for query', function (): void {
    Product::query()->create([
        'name' => 'Drill Press 900',
        'slug' => 'drill-press-900',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 185000,
    ]);

    $this->get(route('search', ['q' => 'drill']))
        ->assertSuccessful()
        ->assertSee('Результаты поиска для «drill»')
        ->assertSee('Drill Press 900');
});
