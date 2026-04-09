<?php

use App\Enums\SettingType;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Products\ProductCurrencyRateSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Schema::dropIfExists('settings');
    Schema::dropIfExists('products');

    Schema::create('settings', function (Blueprint $table): void {
        $table->id();
        $table->string('key')->unique();
        $table->text('value')->nullable();
        $table->string('type')->default('string');
        $table->text('description')->nullable();
        $table->boolean('autoload')->default(true);
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('slug')->unique();
        $table->unsignedInteger('price_amount')->default(0);
        $table->decimal('wholesale_price', 14, 4)->nullable();
        $table->enum('wholesale_currency', ['USD', 'CNY', 'EUR', 'RUR'])->nullable();
        $table->decimal('exchange_rate', 14, 6)->nullable();
        $table->boolean('auto_update_exchange_rate')->default(false);
        $table->decimal('wholesale_price_rub', 14, 2)->nullable();
        $table->decimal('markup_multiplier', 8, 4)->nullable();
        $table->decimal('margin_amount_rub', 14, 2)->nullable();
        $table->timestamps();
    });
});

it('syncs cbr rates, stores settings and updates auto-priced products', function () {
    Http::fake([
        'https://www.cbr.ru/scripts/XML_daily.asp*' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ValCurs Date="09.04.2026">
                <Valute>
                    <CharCode>USD</CharCode>
                    <Nominal>1</Nominal>
                    <Value>82,5000</Value>
                </Valute>
                <Valute>
                    <CharCode>CNY</CharCode>
                    <Nominal>10</Nominal>
                    <Value>113,0000</Value>
                </Valute>
                <Valute>
                    <CharCode>EUR</CharCode>
                    <Nominal>1</Nominal>
                    <Value>94,0000</Value>
                </Valute>
            </ValCurs>
            XML),
    ]);

    $product = Product::query()->create([
        'name' => 'Auto Sync Product',
        'slug' => 'auto-sync-product',
        'price_amount' => 8800,
        'wholesale_price' => '100.0000',
        'wholesale_currency' => 'USD',
        'exchange_rate' => '80.000000',
        'auto_update_exchange_rate' => true,
        'wholesale_price_rub' => '8000.00',
        'markup_multiplier' => '1.1000',
        'margin_amount_rub' => '800.00',
    ]);

    $result = app(ProductCurrencyRateSyncService::class)->sync();

    expect($result['source_date'])->toBe('09.04.2026')
        ->and($result['rates'])->toMatchArray([
            'USD' => 82.5,
            'CNY' => 11.3,
            'EUR' => 94.0,
            'RUR' => 1.0,
        ])
        ->and($result['updated_products'])->toBe(1);

    $usdRateSetting = Setting::query()->where('key', 'product_currency.usd_to_rub')->first();

    expect($usdRateSetting)
        ->not->toBeNull()
        ->and($usdRateSetting?->type)->toBe(SettingType::Float)
        ->and(Setting::query()->where('key', 'product_currency.source_date')->value('value'))->toBe('09.04.2026');

    expect($product->fresh()->exchange_rate)->toBe('82.500000')
        ->and($product->fresh()->wholesale_price_rub)->toBe('8250.00')
        ->and($product->fresh()->price_amount)->toBe(9075)
        ->and($product->fresh()->margin_amount_rub)->toBe('825.00');
});
