<?php

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Config;

it('shows filament edit link on product page for configured filament admin', function (): void {
    Config::set('filament_admin.emails', ['admin@example.com']);

    $adminUser = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    $product = Product::query()->create([
        'name' => 'Тестовый товар для admin edit ссылки',
        'slug' => 'test-product-admin-edit-link',
        'is_active' => true,
        'price_amount' => 150_000,
    ]);

    $editUrl = ProductResource::getUrl('edit', ['record' => $product], isAbsolute: false, panel: 'admin');

    $this->actingAs($adminUser)
        ->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee($editUrl, false)
        ->assertSee('Редактировать', false);
});

it('hides filament edit link on product page for authenticated non admin user', function (): void {
    Config::set('filament_admin.emails', ['admin@example.com']);

    $regularUser = User::factory()->create([
        'email' => 'user@example.com',
    ]);

    $product = Product::query()->create([
        'name' => 'Тестовый товар для user no edit ссылки',
        'slug' => 'test-product-user-no-edit-link',
        'is_active' => true,
        'price_amount' => 150_000,
    ]);

    $editUrl = ProductResource::getUrl('edit', ['record' => $product], isAbsolute: false, panel: 'admin');

    $this->actingAs($regularUser)
        ->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertDontSee($editUrl, false)
        ->assertDontSee('Редактировать', false);
});

it('hides filament edit link on product page for guest', function (): void {
    Config::set('filament_admin.emails', ['admin@example.com']);

    $product = Product::query()->create([
        'name' => 'Тестовый товар для guest no edit ссылки',
        'slug' => 'test-product-guest-no-edit-link',
        'is_active' => true,
        'price_amount' => 150_000,
    ]);

    $editUrl = ProductResource::getUrl('edit', ['record' => $product], isAbsolute: false, panel: 'admin');

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertDontSee($editUrl, false)
        ->assertDontSee('Редактировать', false);
});
