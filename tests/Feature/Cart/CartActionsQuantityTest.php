<?php

use App\Livewire\Pages\Cart\Actions;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use Livewire\Livewire;

it('uses the selected quantity for the product layout and keeps cart quantity in sync', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Товар с количеством',
        'slug' => 'cart-actions-quantity-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 150000,
    ]);

    $component = Livewire::test(Actions::class, [
        'productId' => $product->id,
        'qty' => 1,
        'options' => [],
        'variant' => 'product',
    ])
        ->assertSee('Купить в 1 клик')
        ->call('incrementQty')
        ->call('incrementQty')
        ->assertSet('qty', 3)
        ->call('add')
        ->assertSet('inCart', true);

    $cart = app(CartService::class);
    $cartItem = $cart->getCart()->items()->firstWhere('product_id', $product->id);

    expect($cartItem)->not->toBeNull()
        ->and((int) $cartItem->quantity)->toBe(3);

    $component->call('decrementQty');

    expect((int) $cartItem->fresh()->quantity)->toBe(2);
});
