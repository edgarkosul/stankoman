<?php

use App\Models\Product;
use Tests\TestCase;

uses(TestCase::class);

it('adds numeric aliases for prefixed model codes to searchable data', function (): void {
    $product = new Product([
        'name' => 'Рейсмусовый станок Warrior W0201D 230В',
        'sku' => 'AB-0201',
        'brand' => 'Warrior',
        'price_amount' => 341700,
        'discount_price' => 307530,
    ]);

    $searchableData = $product->toSearchableArray();

    expect($searchableData['search_terms'])
        ->toContain('w0201d')
        ->toContain('0201d')
        ->toContain('0201')
        ->toContain('ab0201')
        ->not->toContain('230');
});

it('keeps aliases for hyphenated model codes without indexing standalone numeric suffixes', function (): void {
    $product = new Product([
        'name' => 'Фуговальный станок JWP-201 230В',
        'brand' => 'Jet',
        'price_amount' => 1000,
    ]);

    $searchableData = $product->toSearchableArray();

    expect($searchableData['search_terms'])
        ->toContain('jwp201')
        ->toContain('201')
        ->not->toContain('230');
});
