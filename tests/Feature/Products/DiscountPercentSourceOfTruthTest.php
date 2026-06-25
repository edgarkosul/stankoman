<?php

use App\Models\Product;

function createDiscountProduct(array $attributes = []): Product
{
    static $counter = 1;
    $index = $counter++;

    return Product::query()->create(array_merge([
        'name' => "Discount Product {$index}",
        'slug' => "discount-product-{$index}",
        'price_amount' => 710,
        'is_active' => true,
        'in_stock' => true,
    ], $attributes));
}

it('derives discount_price from discount_percent on save', function (): void {
    // 5% от 710 = 35.5 ₽ → округление до рубля даёт 675 ₽ (4.93% эффективных),
    // но источником истины остаётся процент.
    $product = createDiscountProduct(['discount_percent' => 5]);

    expect($product->refresh()->discount_price)->toBe(675)
        ->and((float) $product->discount_percent)->toBe(5.0);
});

it('shows the stored percent on the storefront, not the rounded-back value', function (): void {
    $product = createDiscountProduct(['discount_percent' => 5]);

    // calculateDiscountPercent от округлённой цены вернул бы 4.93 — но мы храним 5.
    expect($product->refresh()->display_discount_percent)->toBe(5);
});

it('recomputes discount_price when the base price changes', function (): void {
    $product = createDiscountProduct(['discount_percent' => 5]);

    $product->update(['price_amount' => 1000]);

    // 5% от 1000 = 950 ₽
    expect($product->refresh()->discount_price)->toBe(950)
        ->and($product->display_discount_percent)->toBe(5);
});

it('clears discount_price when percent is zeroed', function (): void {
    $product = createDiscountProduct(['discount_percent' => 5]);
    expect($product->refresh()->discount_price)->toBe(675);

    $product->update(['discount_percent' => 0]);

    expect($product->refresh()->discount_price)->toBeNull();
});

it('leaves supplier compare-at discounts (null percent) untouched', function (): void {
    // Поставщик задаёт «старую цену» напрямую, без процента.
    $product = createDiscountProduct(['discount_price' => 600, 'discount_percent' => null]);

    expect($product->refresh()->discount_price)->toBe(600)
        ->and($product->discount_percent)->toBeNull()
        // эффективный процент вычисляется из цены
        ->and($product->display_discount_percent)->toBe(15);
});
