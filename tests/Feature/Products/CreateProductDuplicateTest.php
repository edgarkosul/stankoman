<?php

use App\Enums\ProductWarranty;
use App\Enums\ProductWholesaleCurrency;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

test('duplicate product copies the product form fields into the new product', function (): void {
    $user = User::factory()->create();

    config([
        'settings.general.filament_admin_emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);

    $source = Product::query()->create([
        'name' => 'Источник для копирования характеристик',
        'slug' => 'source-product-specs-duplicate-test',
        'title' => 'Legacy title source',
        'currency' => 'RUB',
        'price_amount' => 125_000,
        'discount_price' => 111_000,
        'meta_title' => 'Источник META title',
        'meta_description' => 'Источник META description',
        'with_dns' => true,
        'sku' => 'SKU-ORIGINAL',
        'brand' => 'Brand Original',
        'country' => 'Japan',
        'warranty' => ProductWarranty::Months24->value,
        'in_stock' => true,
        'qty' => 7,
        'is_active' => false,
        'is_in_yml_feed' => true,
        'popularity' => 17,
        'wholesale_price' => '95000.5000',
        'wholesale_currency' => ProductWholesaleCurrency::Usd->value,
        'auto_update_exchange_rate' => true,
        'exchange_rate' => '93.50',
        'wholesale_price_rub' => 88800,
        'markup_multiplier' => '1.25',
        'margin_amount_rub' => '22200.50',
        'promo_info' => 'Промо-блок',
        'short' => '<p>Краткое описание</p>',
        'description' => '<p>Описание товара</p>',
        'extra_description' => '<p>Доп. описание</p>',
        'instructions' => '<p>Инструкция</p>',
        'video' => '<p>Видео</p>',
        'image' => 'pics/source-image.jpg',
        'thumb' => 'pics/source-thumb.jpg',
        'gallery' => ['pics/gallery-1.jpg', 'pics/gallery-2.jpg'],
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
        ->and($duplicate?->name)->toBe($source->name.' (копия)')
        ->and($duplicate?->meta_title)->toBe($source->meta_title)
        ->and($duplicate?->meta_description)->toBe($source->meta_description)
        ->and($duplicate?->price_amount)->toBe($source->price_amount)
        ->and($duplicate?->discount_price)->toBe($source->discount_price)
        ->and($duplicate?->with_dns)->toBeTrue()
        ->and($duplicate?->sku)->toBe($source->sku)
        ->and($duplicate?->brand)->toBe($source->brand)
        ->and($duplicate?->country)->toBe($source->country)
        ->and($duplicate?->warranty?->value)->toBe($source->warranty?->value)
        ->and($duplicate?->in_stock)->toBeTrue()
        ->and($duplicate?->is_active)->toBeFalse()
        ->and($duplicate?->is_in_yml_feed)->toBeTrue()
        ->and($duplicate?->popularity)->toBe($source->popularity)
        ->and($duplicate?->title)->toBe($source->title)
        ->and($duplicate?->currency)->toBe($source->currency)
        ->and($duplicate?->qty)->toBe($source->qty)
        ->and($duplicate?->wholesale_price)->toBe($source->wholesale_price)
        ->and($duplicate?->wholesale_currency)->toBe($source->wholesale_currency)
        ->and($duplicate?->auto_update_exchange_rate)->toBeTrue()
        ->and($duplicate?->exchange_rate)->toBe($source->exchange_rate)
        ->and($duplicate?->wholesale_price_rub)->toBe($source->wholesale_price_rub)
        ->and($duplicate?->markup_multiplier)->toBe($source->markup_multiplier)
        ->and($duplicate?->margin_amount_rub)->toBe($source->margin_amount_rub)
        ->and($duplicate?->promo_info)->toBe($source->promo_info)
        ->and($duplicate?->short)->toBe($source->short)
        ->and($duplicate?->extra_description)->toBe($source->extra_description)
        ->and($duplicate?->description)->toBe($source->description)
        ->and($duplicate?->instructions)->toBe($source->instructions)
        ->and($duplicate?->video)->toBe($source->video)
        ->and($duplicate?->image)->toBe($source->image)
        ->and($duplicate?->thumb)->toBe($source->thumb)
        ->and($duplicate?->gallery)->toBe($source->gallery)
        ->and($duplicate?->specs)->toBe($source->specs);
});
