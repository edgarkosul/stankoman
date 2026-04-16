<?php

use App\Livewire\Pages\Cart\Actions;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CartService;
use Livewire\Livewire;

it('adds product to cart and dispatches cart events', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $root = Category::query()->create([
        'name' => 'Каталог',
        'slug' => 'catalog-cart',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $leaf = Category::query()->create([
        'name' => 'Сверлильные станки',
        'slug' => 'drilling-machines',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для корзины',
        'slug' => 'cart-actions-product',
        'sku' => 'CART-100',
        'brand' => 'Stankoman',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 100000,
    ]);
    $product->categories()->attach($leaf->id, ['is_primary' => true]);

    $component = Livewire::test(Actions::class, [
        'productId' => $product->id,
        'qty' => 1,
        'options' => [],
        'variant' => 'card',
    ]);

    $component
        ->assertSet('inCart', false)
        ->call('add')
        ->assertSet('inCart', true)
        ->assertDispatched("cart:updated.{$product->id}")
        ->assertDispatched('cart:updated')
        ->assertDispatched('cart:added', function (string $event, array $params) use ($product): bool {
            return $event === 'cart:added'
                && ($params['productId'] ?? null) === $product->id
                && ($params['product']['id'] ?? null) === $product->id
                && ($params['product']['name'] ?? null) === $product->name
                && ($params['product']['url'] ?? null) === route('product.show', ['product' => $product->slug], false);
        })
        ->assertDispatched('ecommerce:add-to-cart', function (string $event, array $params): bool {
            return $event === 'ecommerce:add-to-cart'
                && ($params['payload']['currencyCode'] ?? null) === 'RUB'
                && ($params['payload']['add']['products'][0]['id'] ?? null) === 'CART-100'
                && ($params['payload']['add']['products'][0]['brand'] ?? null) === 'Stankoman'
                && ($params['payload']['add']['products'][0]['category'] ?? null) === 'Каталог / Сверлильные станки'
                && ($params['payload']['add']['products'][0]['quantity'] ?? null) === 1;
        });

    $component
        ->call('add')
        ->assertNotDispatched('ecommerce:add-to-cart');

    $cart = app(CartService::class);

    expect($cart->isInCart($product->id, null, false))->toBeTrue()
        ->and($cart->uniqueProductsCount())->toBe(1);
});
