<?php

use App\Models\LegacyProduct;
use App\Models\Product;
use App\Support\NameNormalizer;

test('it redirects enabled matched legacy products to intertooler product pages', function (): void {
    config()->set('legacy.kraton.redirect_base_url', 'https://intertooler.test');

    $product = createResolverProduct([
        'name' => 'Matched product',
        'slug' => 'matched-product',
    ]);

    LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/old-product.php',
        'name' => 'Old product',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'redirect_enabled' => true,
    ]);

    $this->get(route('legacy.kraton.resolve', ['path' => '/old-product.php']))
        ->assertRedirect('https://intertooler.test/product/matched-product');
});

test('it returns not found when a legacy product has no enabled redirect', function (): void {
    $product = createResolverProduct();

    LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/disabled-product.php',
        'name' => 'Disabled product',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'redirect_enabled' => false,
    ]);

    $this->get(route('legacy.kraton.resolve', ['path' => '/disabled-product.php']))
        ->assertNotFound();
});

test('it returns not found when a matched product is inactive', function (): void {
    $product = createResolverProduct([
        'is_active' => false,
    ]);

    LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/inactive-product.php',
        'name' => 'Inactive product',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'redirect_enabled' => true,
    ]);

    $this->get(route('legacy.kraton.resolve', ['path' => '/inactive-product.php']))
        ->assertNotFound();
});

test('it returns not found for missing and non php paths', function (array $query): void {
    $this->get(route('legacy.kraton.resolve', $query))
        ->assertNotFound();
})->with([
    'missing path' => [[]],
    'empty path' => [['path' => '']],
    'non php path' => [['path' => '/old-category.htm']],
]);

test('it normalizes absolute legacy urls to source paths', function (): void {
    config()->set('legacy.kraton.redirect_base_url', 'https://intertooler.test');

    $product = createResolverProduct([
        'slug' => 'absolute-url-product',
    ]);

    LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/absolute-url-product.php',
        'name' => 'Absolute URL product',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'redirect_enabled' => true,
    ]);

    $this->get(route('legacy.kraton.resolve', [
        'path' => 'https://kratonkuban.ru/absolute-url-product.php?utm=legacy',
    ]))->assertRedirect('https://intertooler.test/product/absolute-url-product');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createResolverProduct(array $attributes = []): Product
{
    $attributes = array_merge([
        'name' => 'Resolver product',
        'slug' => 'resolver-product-'.fake()->unique()->slug(),
        'sku' => 'RESOLVER-'.fake()->unique()->bothify('###'),
        'price_amount' => 1000,
        'currency' => 'RUB',
        'is_active' => true,
    ], $attributes);

    $attributes['name_normalized'] = NameNormalizer::normalize($attributes['name']);

    return Product::query()->create($attributes);
}
