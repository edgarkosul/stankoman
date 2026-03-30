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

it('uses custom trash icon with accent hover state on clear cart button', function (): void {
    $cartPage = file_get_contents(resource_path('views/livewire/pages/cart/index.blade.php'));

    expect($cartPage)
        ->not->toBeFalse()
        ->toContain('name="trash"')
        ->toContain('[&_.icon-base]:text-gray-800')
        ->toContain('[&_.icon-accent]:text-brand-red')
        ->toContain('group-hover:[&_.icon-accent]:text-brand-red/50')
        ->toContain('group-hover:[&_.icon-base]:text-gray-100');
});
