<?php

use App\Models\Product;

it('renders cart modal markup on product page', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар для модалки корзины',
        'slug' => 'cart-modal-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 18900,
    ]);

    $this->get(route('product.show', ['product' => $product->slug]))
        ->assertSuccessful()
        ->assertSee('id="cart-modal"', false)
        ->assertSee('Товар добавлен в корзину');
});
