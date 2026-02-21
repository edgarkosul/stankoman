<?php

use App\Livewire\Pages\Product\CompareToggle;
use App\Models\Product;
use App\Support\CompareService;
use Livewire\Livewire;

it('toggles product in compare list and dispatches compare events', function (): void {
    $product = Product::query()->create([
        'name' => 'Товар для toggle сравнения',
        'slug' => 'toggle-compare-product',
        'is_active' => true,
        'price_amount' => 85000,
    ]);

    $service = app(CompareService::class);

    Livewire::test(CompareToggle::class, [
        'productId' => $product->id,
        'variant' => 'card',
    ])
        ->assertSet('added', false)
        ->call('toggle')
        ->assertSet('added', true)
        ->assertDispatched('compare:list-updated')
        ->assertDispatched('compare:updated');

    expect($service->contains($product->id))->toBeTrue();

    Livewire::test(CompareToggle::class, [
        'productId' => $product->id,
        'variant' => 'card',
    ])
        ->assertSet('added', true)
        ->call('toggle')
        ->assertSet('added', false)
        ->assertDispatched('compare:list-updated')
        ->assertDispatched('compare:updated');

    expect($service->contains($product->id))->toBeFalse();
});
