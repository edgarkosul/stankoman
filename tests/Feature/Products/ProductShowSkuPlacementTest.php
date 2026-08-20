<?php

use App\Models\Product;

it('shows the sku right under the product title', function (): void {
    $product = Product::query()->create([
        'name' => 'Компрессор с артикулом под названием',
        'slug' => 'product-sku-under-title',
        'sku' => 'S-7.5DF',
        'is_active' => true,
        'price_amount' => 117_990,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSeeInOrder([$product->name, 'Артикул', $product->sku, 'Скачать PDF'], false);
});

it('does not print an empty sku line', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар без артикула',
        'slug' => 'product-without-sku',
        'is_active' => true,
        'price_amount' => 1_000,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertDontSee('Артикул', false);
});
