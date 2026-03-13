<?php

use App\Models\Supplier;
use App\Support\CatalogImport\Drivers\ImportDriverRegistry;

test('driver registry filters drivers by supplier and picks compatible defaults', function () {
    $registry = app(ImportDriverRegistry::class);

    $genericSupplier = new Supplier([
        'name' => 'Acme Industrial',
        'slug' => 'acme-industrial',
    ]);
    $vactoolSupplier = new Supplier([
        'name' => 'Vactool',
        'slug' => 'vactool',
    ]);
    $metalmasterSupplier = new Supplier([
        'name' => 'Metalmaster',
        'slug' => 'metalmaster',
    ]);

    expect(array_keys($registry->optionsForSupplier(null)))->toBe(['yandex_market_feed']);
    expect(array_keys($registry->optionsForSupplier($genericSupplier)))->toBe(['yandex_market_feed']);
    expect(array_keys($registry->optionsForSupplier($vactoolSupplier)))->toBe(['vactool_html', 'yandex_market_feed']);
    expect(array_keys($registry->optionsForSupplier($metalmasterSupplier)))->toBe(['metalmaster_html', 'yandex_market_feed']);

    expect($registry->defaultForSupplier(null)->key())->toBe('yandex_market_feed');
    expect($registry->defaultForSupplier($genericSupplier)->key())->toBe('yandex_market_feed');
    expect($registry->defaultForSupplier($vactoolSupplier)->key())->toBe('vactool_html');
    expect($registry->defaultForSupplier($metalmasterSupplier)->key())->toBe('metalmaster_html');

    expect(array_keys($registry->optionsForSupplier($genericSupplier, 'vactool_html')))->toBe([
        'vactool_html',
        'yandex_market_feed',
    ]);
});
