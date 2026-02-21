<?php

it('resolves cart route to cart page path', function (): void {
    expect(route('cart.index', [], false))->toBe('/cart');
});

it('renders empty cart page', function (): void {
    $this->get(route('cart.index'))
        ->assertSuccessful()
        ->assertSee('Корзина')
        ->assertSee('Корзина пуста.');
});
