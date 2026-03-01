<?php

use App\Models\Product;

it('shows request-based price label when product price is zero', function (): void {
    $product = Product::query()->create([
        'name' => 'Тестовый товар с ценой по запросу',
        'slug' => 'test-product-request-price',
        'is_active' => true,
        'price_amount' => 0,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('Цена по запросу')
        ->assertDontSee(price(0));
});
