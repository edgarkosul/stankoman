<?php

use App\Livewire\Pages\Cart\Index;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use Livewire\Livewire;

it('removes a cart item from the cart page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createCartProduct([
        'name' => 'Удаляемый товар',
        'slug' => 'removable-cart-product',
    ]);

    $cart = app(CartService::class);
    $cart->addItem($product->id, 2);

    $cartItem = $cart->getCart()->items()->firstOrFail();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee($product->name)
        ->call('removeItem', $cartItem->id)
        ->assertDispatched('cart:soft-remove', function (string $event, array $params) use ($cartItem): bool {
            return $event === 'cart:soft-remove'
                && ($params['id'] ?? null) === $cartItem->id;
        })
        ->call('finalizeRemove', $cartItem->id)
        ->assertDontSee($product->name)
        ->assertSee('Корзина пуста.')
        ->assertSet('totalQty', 0)
        ->assertSet('totalSum', 0.0);

    $this->assertModelMissing($cartItem);

    expect(app(CartService::class)->uniqueProductsCount())->toBe(0);
});

it('renders mobile-safe price stack and remove button on the cart page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createCartProduct([
        'name' => 'Товар со скидкой',
        'slug' => 'discounted-cart-product',
        'price_amount' => 100000,
        'discount_price' => 80000,
    ]);

    app(CartService::class)->addItem($product->id, 1);

    $this->get(route('cart.index'))
        ->assertSuccessful()
        ->assertSee('wire:click="removeItem(', false)
        ->assertSee('aria-label="Удалить товар из корзины"', false)
        ->assertSee('max-xs:flex-col max-xs:items-stretch', false)
        ->assertSee('max-xs:flex-col max-xs:items-end max-xs:gap-1', false);
});

function createCartProduct(array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Товар корзины',
        'slug' => 'cart-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 150000,
    ], $attributes));
}
