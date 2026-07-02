<?php

use App\Support\CatalogImport\Enums\ImportRunType;

it('labels every known run type', function () {
    expect(ImportRunType::labelFor('stalex_yml_products'))->toBe('Stalex');
    expect(ImportRunType::labelFor('metaltec_products'))->toBe('Metaltec');
    expect(ImportRunType::labelFor('yandex_market_feed_products'))->toBe('Yandex Market Feed');
    expect(ImportRunType::labelFor('yandex_market_feed_deactivation'))->toBe('Деактивация Yandex Feed');
    expect(ImportRunType::labelFor('products'))->toBe('Excel товары');
    expect(ImportRunType::labelFor('specs_match'))->toBe('Specs match');
});

it('falls back to the raw value for unknown/legacy types', function () {
    expect(ImportRunType::labelFor('some_legacy_type'))->toBe('some_legacy_type');
    expect(ImportRunType::labelFor(''))->toBe('unknown');
    expect(ImportRunType::colorFor('some_legacy_type'))->toBe('gray');
});

it('maps badge colors for known types', function () {
    expect(ImportRunType::colorFor('vactool_products'))->toBe('primary');
    expect(ImportRunType::colorFor('stalex_yml_products'))->toBe('success');
    expect(ImportRunType::colorFor('metaltec_products'))->toBe('success');
    expect(ImportRunType::colorFor('specs_match'))->toBe('info');
});

it('lists only supplier-import types in the summary whitelist', function () {
    $values = ImportRunType::supplierImportSummaryValues();

    expect($values)->toContain('stalex_yml_products')
        ->toContain('metaltec_products')
        ->toContain('yandex_market_feed_products')
        ->toContain('yandex_market_feed_deactivation');

    // Excel / filters / specs-match are generated outside the supplier-import screen.
    expect($values)->not->toContain('products')
        ->not->toContain('category_filters')
        ->not->toContain('specs_match');
});
