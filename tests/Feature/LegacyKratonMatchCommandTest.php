<?php

use App\Models\LegacyProduct;
use App\Models\Product;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\Artisan;

test('it matches legacy products by exact sku', function (): void {
    $product = createMatchProduct([
        'name' => 'Новый товар',
        'slug' => 'new-product',
        'sku' => 'ABC-123',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/old-product.php',
        'name' => 'Старый товар',
        'sku' => 'ABC-123',
    ]);

    $exitCode = Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($exitCode)->toBe(0)
        ->and($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('sku_exact')
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it matches legacy products by normalized sku', function (): void {
    $product = createMatchProduct([
        'name' => 'Товар по нормализованному артикулу',
        'slug' => 'normalized-sku-product',
        'sku' => 'AB C_123',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/normalized-sku.php',
        'name' => 'Legacy',
        'sku' => 'abc-123',
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('sku_normalized')
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it matches legacy products by normalized name when sku is empty', function (): void {
    $product = createMatchProduct([
        'name' => 'Спиральный вал Helical 150мм',
        'slug' => 'spiralny-val-helical-150mm',
        'sku' => 'CURRENT-SKU',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/name-match.php',
        'name' => '  Спиральный   вал Helical 150мм  ',
        'sku' => null,
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('name_normalized')
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it does not enable redirect for ambiguous exact sku matches', function (): void {
    createMatchProduct([
        'name' => 'Первый товар',
        'slug' => 'first-duplicate-sku-product',
        'sku' => 'DUPLICATE',
    ]);

    createMatchProduct([
        'name' => 'Второй товар',
        'slug' => 'second-duplicate-sku-product',
        'sku' => 'DUPLICATE',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/ambiguous.php',
        'name' => 'Legacy ambiguous',
        'sku' => 'DUPLICATE',
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBeNull()
        ->and($legacyProduct->match_strategy)->toBeNull()
        ->and($legacyProduct->redirect_enabled)->toBeFalse();
});

test('it clears previous match when product is no longer uniquely matched', function (): void {
    $product = createMatchProduct([
        'name' => 'Удаляемый матч',
        'slug' => 'cleared-match-product',
        'sku' => 'CLEAR-ME',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/clear-match.php',
        'name' => 'Legacy clear',
        'sku' => 'UNKNOWN',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'redirect_enabled' => true,
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBeNull()
        ->and($legacyProduct->match_strategy)->toBeNull()
        ->and($legacyProduct->redirect_enabled)->toBeFalse();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createMatchProduct(array $attributes): Product
{
    $attributes = array_merge([
        'name' => 'Тестовый товар',
        'slug' => 'test-product-'.fake()->unique()->slug(),
        'sku' => 'SKU-'.fake()->unique()->bothify('###'),
        'price_amount' => 1000,
        'currency' => 'RUB',
        'is_active' => true,
    ], $attributes);

    $attributes['name_normalized'] = NameNormalizer::normalize($attributes['name']);

    return Product::query()->create($attributes);
}
