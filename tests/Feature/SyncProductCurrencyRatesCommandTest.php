<?php

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

it('runs the product currency sync command and updates auto-priced products', function () {
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
        'name' => 'Feature Auto Sync Product',
        'slug' => 'feature-auto-sync-product',
        'price_amount' => 8800,
        'wholesale_price' => '100.0000',
        'wholesale_currency' => 'USD',
        'exchange_rate' => '80.000000',
        'auto_update_exchange_rate' => true,
        'wholesale_price_rub' => '8000.00',
        'markup_multiplier' => '1.1000',
        'margin_amount_rub' => '800.00',
    ]);

    $this->artisan('products:sync-currency-rates')
        ->expectsOutput('Запуск синхронизации курсов ЦБ РФ для товаров...')
        ->expectsOutput('Курсы обновлены: USD/RUR 82.5, CNY/RUR 11.3, EUR/RUR 94 (дата 09.04.2026). Обновлено товаров: 1.')
        ->assertSuccessful();

    expect(Setting::query()->where('key', 'product_currency.usd_to_rub')->value('value'))->toBe('82.500000')
        ->and($product->fresh()->exchange_rate)->toBe('82.500000')
        ->and($product->fresh()->price_amount)->toBe(9075);
});
