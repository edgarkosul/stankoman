<?php

use App\Livewire\Pages\Cart\Icon;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use Livewire\Livewire;

it('redirects from cart icon when cart has products', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Товар для иконки корзины',
        'slug' => 'cart-icon-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 90000,
    ]);

    app(CartService::class)->addItem($product->id);

    Livewire::test(Icon::class)
        ->call('goToCart')
        ->assertRedirect(route('cart.index'));
});

it('does not redirect from cart icon when cart is empty', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Icon::class)
        ->call('goToCart')
        ->assertNoRedirect();
});
