<?php

use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Html\HtmlDocumentRecord;
use App\Support\CatalogImport\Html\HtmlRecord;
use App\Support\CatalogImport\Suppliers\Vactool\VactoolSupplierAdapter;

it('maps vactool html document record into product payload', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Промышленный пылесос VT-9000',
        'description' => 'Описание из JSON-LD',
        'brand' => ['name' => 'Vactool'],
        'image' => ['https://cdn.vactool.ru/images/vt-9000-main.jpg'],
        'additionalProperty' => [
            ['name' => 'Мощность', 'value' => '2200 Вт'],
        ],
        'offers' => [
            'price' => '12345',
            'priceCurrency' => 'RUB',
            'availability' => 'https://schema.org/InStock',
            'inventoryLevel' => ['value' => 4],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body></body></html>';

    $record = new HtmlDocumentRecord(
        url: 'https://vactool.ru/catalog/product-vt-9000',
        document: new HtmlRecord(index: 0, html: $html),
    );

    $result = (new VactoolSupplierAdapter)->mapRecord($record);

    expect($result->isSuccess())->toBeTrue();
    expect($result->payload?->externalId)->toBe('product-vt-9000');
    expect($result->payload?->name)->toBe('Промышленный пылесос VT-9000');
    expect($result->payload?->brand)->toBe('Vactool');
    expect($result->payload?->priceAmount)->toBe(12345);
    expect($result->payload?->currency)->toBe('RUB');
    expect($result->payload?->qty)->toBe(4);
    expect($result->payload?->inStock)->toBeTrue();
    expect($result->payload?->images)->toContain('https://cdn.vactool.ru/images/vt-9000-main.jpg');
    expect($result->payload?->attributes)->toHaveCount(1);
});

it('returns fatal error for invalid record type in vactool adapter', function () {
    $result = (new VactoolSupplierAdapter)->mapRecord(['invalid']);

    expect($result->isSuccess())->toBeFalse();
    expect($result->hasFatalError())->toBeTrue();
    expect($result->errors[0]->code)->toBe('invalid_record_type');
    expect($result->errors[0]->level)->toBe(ImportErrorLevel::Fatal);
});
