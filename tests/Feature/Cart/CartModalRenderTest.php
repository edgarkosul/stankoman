<?php

use App\Models\Product;

it('renders cart modal markup on product page', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар для модалки корзины',
        'slug' => 'cart-modal-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 18900,
    ]);

    $this->get(route('product.show', ['product' => $product->slug]))
        ->assertSuccessful()
        ->assertSee('id="cart-modal"', false)
        ->assertSee('Товар добавлен в корзину');
});

it('renders unique cart action keys for mobile and desktop product summaries', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар с двумя summary',
        'slug' => 'cart-summary-keys-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 18900,
    ]);

    $this->get(route('product.show', ['product' => $product->slug]))
        ->assertSuccessful()
        ->assertSee('wire:key="cart-product-'.$product->id.'-desktop"', false)
        ->assertSee('wire:key="cart-product-'.$product->id.'-mobile"', false)
        ->assertDontSee('wire:key="cart-product-'.$product->id.'"', false);
});

it('renders the product cart actions component with a stable root container', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар со стабильным root',
        'slug' => 'cart-root-product',
        'is_active' => true,
        'in_stock' => true,
        'price_amount' => 18900,
    ]);

    $content = $this->get(route('product.show', ['product' => $product->slug]))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('wire:name="pages.cart.actions" class="z-30 w-full grid gap-3 sm:grid-cols-[148px_minmax(0,1fr)_minmax(0,1fr)] lg:grid-cols-[148px_minmax(0,1fr)]"')
        ->toContain('disabled:cursor-not-allowed lg:col-span-2')
        ->not->toContain('wire:name="pages.cart.actions" class="inline-flex h-14 items-center justify-between rounded-2xl border px-3 shadow-sm transition');
});
