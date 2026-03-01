<?php

use App\Livewire\RecentProductsSlider;
use App\Models\Product;
use Livewire\Livewire;

test('recent products slider keeps request order and excludes inactive products', function (): void {
    $first = Product::query()->create([
        'name' => 'Recent first',
        'slug' => 'recent-first',
        'price_amount' => 100_000,
        'discount_price' => 90_000,
        'is_active' => true,
    ]);

    $second = Product::query()->create([
        'name' => 'Recent second',
        'slug' => 'recent-second',
        'price_amount' => 110_000,
        'discount_price' => 95_000,
        'is_active' => true,
    ]);

    $inactive = Product::query()->create([
        'name' => 'Recent inactive',
        'slug' => 'recent-inactive',
        'price_amount' => 120_000,
        'discount_price' => 96_000,
        'is_active' => false,
    ]);

    $test = Livewire::test(RecentProductsSlider::class)
        ->call('load', [$second->id, $first->id, $inactive->id, $second->id, 0, -1]);

    $instance = $test->instance();
    $resolvedIds = $instance->products->pluck('id')->all();

    expect($resolvedIds)
        ->toBe([$second->id, $first->id])
        ->not->toContain($inactive->id);
});

test('product page registers current product in recent store', function (): void {
    $product = Product::query()->create([
        'name' => 'Recent trackable product',
        'slug' => 'recent-trackable-product',
        'price_amount' => 130_000,
        'discount_price' => null,
        'is_active' => true,
    ]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('$wire.load($store.recent ? $store.recent.ids() : [])', false)
        ->assertSee('$store.recent && $store.recent.add('.$product->id.')', false);
});

test('app js registers recent products alpine store', function (): void {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)
        ->toContain('const registerRecentProductsStore = (alpine) =>')
        ->toContain("const storageKey = 'stankoman:recent:v1';")
        ->toContain("alpine.store('recent', {")
        ->toContain('registerRecentProductsStore(alpine);');
});
