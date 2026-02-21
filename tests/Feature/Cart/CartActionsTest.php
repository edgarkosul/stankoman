<?php

use App\Livewire\Pages\Cart\Actions;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use Livewire\Livewire;

it('adds product to cart and dispatches cart events', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Товар для корзины',
        'slug' => 'cart-actions-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ]);

    Livewire::test(Actions::class, [
        'productId' => $product->id,
        'qty' => 1,
        'options' => [],
        'variant' => 'card',
    ])
        ->assertSet('inCart', false)
        ->call('add')
        ->assertSet('inCart', true)
        ->assertDispatched('cart:updated')
        ->assertDispatched('cart:added');

    $cart = app(CartService::class);

    expect($cart->isInCart($product->id, null, false))->toBeTrue()
        ->and($cart->uniqueProductsCount())->toBe(1);
});
