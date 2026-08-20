<?php

use App\Models\Product;
use App\Models\User;

function discountedProduct(): Product
{
    return Product::query()->create([
        'name' => 'Станок со скидкой для зарегистрированных',
        'slug' => 'stanok-so-skidkoj-dlya-zaregistrirovannyh',
        'sku' => 'DISC-1',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 300_000,
        'discount_price' => 270_000,
    ]);
}

it('hides the discounted price from guests and offers to register', function (): void {
    $product = discountedProduct();

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee(price(300_000), false)
        ->assertDontSee(price(270_000), false)
        ->assertSee('Цена со скидкой — для зарегистрированных', false);
});

it('shows the discounted price to authenticated customers', function (): void {
    $product = discountedProduct();

    $this->actingAs(User::factory()->create())
        ->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee(price(270_000), false)
        ->assertDontSee('Цена со скидкой — для зарегистрированных', false);
});

it('keeps the regular price for guests when there is no discount', function (): void {
    $product = Product::query()->create([
        'name' => 'Станок без скидки',
        'slug' => 'stanok-bez-skidki',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 300_000,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee(price(300_000), false)
        ->assertDontSee('Цена со скидкой — для зарегистрированных', false);
});
