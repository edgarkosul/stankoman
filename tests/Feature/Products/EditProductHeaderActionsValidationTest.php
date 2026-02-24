<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

test('edit product header actions render with incomplete specs rows', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Тестовый товар для header actions',
        'slug' => 'test-product-header-actions-validation',
        'price_amount' => 5_000,
        'is_active' => false,
    ]);

    Livewire::test(EditProduct::class, [
        'record' => $product->getRouteKey(),
    ])
        ->assertSee('Сгенерировать WebP')
        ->set('data.specs', [
            ['name' => 'Мощность', 'value' => '', 'source' => 'manual'],
            ['name' => '', 'value' => '2200 Вт', 'source' => 'manual'],
        ])
        ->assertSee('Сгенерировать WebP');
});
