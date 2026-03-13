<?php

use App\Enums\ProductWarranty;
use App\Models\Product;
use Tests\TestCase;

uses(TestCase::class);

it('provides fixed warranty options', function (): void {
    expect(ProductWarranty::options())->toBe([
        '12' => '12 мес.',
        '24' => '24 мес.',
        '36' => '36 мес.',
        '60' => '60 мес.',
    ]);
});

it('normalizes warranty inputs from raw values and labels', function (): void {
    expect(ProductWarranty::normalizeInput('12'))->toBe(ProductWarranty::Months12->value)
        ->and(ProductWarranty::normalizeInput(24))->toBe(ProductWarranty::Months24->value)
        ->and(ProductWarranty::normalizeInput('36 мес.'))->toBe(ProductWarranty::Months36->value)
        ->and(ProductWarranty::normalizeInput('60 месяцев'))->toBe(ProductWarranty::Months60->value)
        ->and(ProductWarranty::normalizeInput(ProductWarranty::Months24))->toBe(ProductWarranty::Months24->value)
        ->and(ProductWarranty::normalizeInput('6 мес.'))->toBeNull();
});

it('casts warranty to enum and builds display label', function (): void {
    $product = new Product;
    $product->warranty = ProductWarranty::Months24->value;

    expect($product->warranty)->toBe(ProductWarranty::Months24)
        ->and($product->warranty_display)->toBe('24 мес.');
});

it('returns null display for legacy invalid warranty values', function (): void {
    $product = new Product;
    $product->setRawAttributes(['warranty' => '6 мес.']);

    expect($product->warranty_display)->toBeNull();
});
