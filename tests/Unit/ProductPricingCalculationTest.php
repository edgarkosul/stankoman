<?php

use App\Models\Product;

it('calculates pricing values from wholesale inputs and the site price', function (): void {
    $wholesalePriceRub = Product::calculateWholesalePriceRub('100,50', '92.25');
    $sitePriceAmount = Product::calculateSitePriceAmount($wholesalePriceRub, '1.2');
    $marginAmountRub = Product::calculateMarginAmountRub($sitePriceAmount, $wholesalePriceRub);

    expect($wholesalePriceRub)->toBe(9271.13)
        ->and($sitePriceAmount)->toBe(11125)
        ->and($marginAmountRub)->toBe(1853.87);
});

it('keeps calculations nullable when source values are missing', function (): void {
    expect(Product::calculateWholesalePriceRub(null, 90))->toBeNull()
        ->and(Product::calculateSitePriceAmount(1000, null))->toBeNull()
        ->and(Product::calculateMarginAmountRub(null, 1000))->toBeNull();
});
