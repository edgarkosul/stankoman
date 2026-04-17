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

    $component->assertSeeHtml('cart-action-foreground-muted')
        ->assertSeeHtml('cart-action-icon-muted');
});

it('renders split card actions and opens one click order with the current quantity', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар карточки',
        'slug' => 'card-actions-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 75000,
    ]);

    Livewire::test(Actions::class, [
        'productId' => $product->id,
        'qty' => 2,
        'options' => [],
        'variant' => 'card',
    ])
        ->assertSee('В корзину')
        ->assertSee('Купить в 1 клик')
        ->call('openOneClickOrder')
        ->assertDispatched('one-click-order:open', function (string $event, array $params) use ($product): bool {
            return $event === 'one-click-order:open'
                && (int) ($params['productId'] ?? 0) === $product->id
                && (int) ($params['quantity'] ?? 0) === 2;
        });
});
