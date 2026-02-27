<?php

use App\Models\Product;
use App\Models\User;
use App\Support\CartService;

it('displays configured vat rate for cart lines', function (): void {
    config()->set('settings.product.stavka_nds', 17);

    $user = User::factory()->create();
    $this->actingAs($user);

    $includedVatProduct = Product::query()->create([
        'name' => 'Товар с НДС',
        'slug' => 'cart-vat-with-dns',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 10500,
        'with_dns' => true,
    ]);

    $excludedVatProduct = Product::query()->create([
        'name' => 'Товар без НДС',
        'slug' => 'cart-vat-without-dns',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 8800,
        'with_dns' => false,
    ]);

    $cart = app(CartService::class);
    $cart->addItem($includedVatProduct->id);
    $cart->addItem($excludedVatProduct->id);

    $this->get(route('cart.index'))
        ->assertSuccessful()
        ->assertSee('НДС 17% в том числе')
        ->assertSee('+ НДС 17%');
});
