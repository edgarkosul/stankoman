<?php

use App\Models\Product;

it('calculates pricing values from wholesale inputs and the site price', function (): void {
    $wholesalePriceRub = Product::calculateWholesalePriceRub('100,50', '92.25');
    $sitePriceAmount = Product::calculateSitePriceAmount($wholesalePriceRub, '1.2');
    $marginAmountRub = Product::calculateMarginAmountRub($sitePriceAmount, $wholesalePriceRub);

    expect($wholesalePriceRub)->toBe(9271.0)
        ->and($sitePriceAmount)->toBe(11125)
        ->and($marginAmountRub)->toBe(1854.0);
});

it('calculates discount percent and discount price from site price', function (): void {
    $discountPercent = Product::calculateDiscountPercent(10854, 9769);
    $discountPrice = Product::calculateDiscountPrice(10854, '10');

    expect($discountPercent)->toBe(10.0)
        ->and($discountPrice)->toBe(9769);
});

it('keeps calculations nullable when source values are missing', function (): void {
    expect(Product::calculateWholesalePriceRub(null, 90))->toBeNull()
        ->and(Product::calculateSitePriceAmount(1000, null))->toBe(1000)
        ->and(Product::calculateMarginAmountRub(null, 1000))->toBeNull();
});
