<?php

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;

test('product edit page renders floating scroll to top button', function (): void {
    config([
        'settings.general.filament_admin_emails' => ['admin@example.com'],
    ]);

    $this->actingAs(User::factory()->create([
        'email' => 'admin@example.com',
    ]));

    $product = Product::query()->create([
        'name' => 'Тестовый товар для плавающей кнопки',
        'slug' => 'test-product-floating-scroll-button',
        'price_amount' => 7_500,
        'is_active' => false,
    ]);

    $this->get(ProductResource::getUrl('edit', ['record' => $product], panel: 'admin'))
        ->assertOk()
        ->assertSee('aria-label="Наверх"', false)
        ->assertSee("window.scrollTo({ top: 0, behavior: 'smooth' })", false);
});
