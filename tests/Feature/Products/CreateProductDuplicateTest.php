<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

test('duplicate product copies specs table into the new product', function (): void {
    $user = User::factory()->create();

    config([
        'filament_admin.emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);

    $source = Product::query()->create([
        'name' => 'Источник для копирования характеристик',
        'slug' => 'source-product-specs-duplicate-test',
        'price_amount' => 125_000,
        'is_active' => false,
        'specs' => [
            [
                'name' => 'Мощность',
                'value' => '2200 Вт',
                'source' => 'manual',
            ],
            [
                'name' => 'Объем бака',
                'value' => '80 л',
                'source' => 'import',
            ],
        ],
    ]);

    Livewire::withQueryParams(['from' => $source->id])
        ->test(CreateProduct::class)
        ->assertSet('sourceProductId', $source->id)
        ->call('create')
        ->assertHasNoFormErrors();

    $duplicate = Product::query()
        ->where('slug', $source->slug.'-copy')
        ->first();

    expect($duplicate)->not->toBeNull()
        ->and($duplicate?->id)->not->toBe($source->id)
        ->and($duplicate?->specs)->toBe($source->specs);
});
