<?php

use App\Livewire\Pages\Product\FavoriteToggle;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

it('toggles product in favorites list and dispatches favorites events', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Товар для toggle избранного',
        'slug' => 'toggle-favorite-product',
        'is_active' => true,
        'price_amount' => 75000,
    ]);

    Livewire::test(FavoriteToggle::class, [
        'productId' => $product->id,
        'variant' => 'card',
    ])
        ->assertSet('added', false)
        ->call('toggle')
        ->assertSet('added', true)
        ->assertDispatched('favorites:list-updated')
        ->assertDispatched('favorites:updated');

    expect($user->favoriteProducts()->whereKey($product->id)->exists())->toBeTrue();

    Livewire::test(FavoriteToggle::class, [
        'productId' => $product->id,
        'variant' => 'card',
    ])
        ->assertSet('added', true)
        ->call('toggle')
        ->assertSet('added', false)
        ->assertDispatched('favorites:list-updated')
        ->assertDispatched('favorites:updated');

    expect($user->favoriteProducts()->whereKey($product->id)->exists())->toBeFalse();
});
