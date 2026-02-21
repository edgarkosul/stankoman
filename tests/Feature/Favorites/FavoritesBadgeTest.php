<?php

use App\Livewire\Header\FavoritesBadge;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

it('redirects from favorites badge when favorites list has items', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::query()->create([
        'name' => 'Товар для бейджа избранного',
        'slug' => 'favorites-badge-product',
        'is_active' => true,
        'price_amount' => 65000,
    ]);

    $user->favoriteProducts()->syncWithoutDetaching([$product->id]);

    Livewire::test(FavoritesBadge::class)
        ->call('goToFavoritesPage')
        ->assertRedirect(route('favorites.index'));
});

it('does not redirect from favorites badge when favorites list is empty', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(FavoritesBadge::class)
        ->call('goToFavoritesPage')
        ->assertNoRedirect();
});
