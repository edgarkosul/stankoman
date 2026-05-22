<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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

test('saving edit product clears legacy thumb when visible image fields changed', function (): void {
    Storage::fake('public');

    $this->actingAs(User::factory()->create());

    foreach ([
        'pics/original-image.jpg',
        'pics/original-thumb.jpg',
        'pics/replaced-image.jpg',
    ] as $file) {
        Storage::disk('public')->put($file, 'test-image');
    }

    $product = Product::query()->create([
        'name' => 'Тестовый товар для очистки thumb',
        'slug' => 'test-product-clear-thumb-on-image-change',
        'price_amount' => 5_000,
        'is_active' => false,
        'image' => 'pics/original-image.jpg',
        'thumb' => 'pics/original-thumb.jpg',
        'gallery' => [],
    ]);

    Livewire::test(EditProduct::class, [
        'record' => $product->getRouteKey(),
    ])
        ->fillForm([
            'image' => ['pics/replaced-image.jpg'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->image)->toBe('pics/replaced-image.jpg')
        ->and($product->thumb)->toBeNull();
});

test('saving edit product keeps legacy thumb when image fields are unchanged', function (): void {
    Storage::fake('public');

    $this->actingAs(User::factory()->create());

    foreach ([
        'pics/original-image.jpg',
        'pics/original-thumb.jpg',
    ] as $file) {
        Storage::disk('public')->put($file, 'test-image');
    }

    $product = Product::query()->create([
        'name' => 'Тестовый товар для сохранения thumb',
        'slug' => 'test-product-keep-thumb-without-image-change',
        'price_amount' => 5_000,
        'is_active' => false,
        'image' => 'pics/original-image.jpg',
        'thumb' => 'pics/original-thumb.jpg',
        'gallery' => [],
    ]);

    Livewire::test(EditProduct::class, [
        'record' => $product->getRouteKey(),
    ])
        ->fillForm([
            'name' => 'Тестовый товар для сохранения thumb updated',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->thumb)->toBe('pics/original-thumb.jpg');
});
