<?php

use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Html\HtmlDocumentRecord;
use App\Support\CatalogImport\Html\HtmlRecord;
use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierAdapter;

it('maps metalmaster html document record into product payload', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Станок токарный Metal Master Z 50100 DRO',
        'description' => 'Описание товара',
        'brand' => ['name' => 'MetalMaster'],
        'image' => ['https://metalmaster.ru/files/originals/z50100-main.jpg'],
        'additionalProperty' => [
            ['name' => 'Мощность', 'value' => '5.5 кВт'],
        ],
        'offers' => [
            'price' => '1049972',
            'priceCurrency' => 'RUB',
            'availability' => 'https://schema.org/InStock',
            'inventoryLevel' => ['value' => 7],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<html><head><script type="application/ld+json">'.$jsonLd.'</script><title>Metal Master Z 50100 DRO</title></head><body><h1>Станок токарный Metal Master Z 50100 DRO</h1></body></html>';

    $record = new HtmlDocumentRecord(
        url: 'https://metalmaster.ru/promyshlennye/z50100-dro/',
        document: new HtmlRecord(index: 0, html: $html),
        meta: ['bucket' => 'promyshlennye'],
    );

    $result = (new MetalmasterSupplierAdapter)->mapRecord($record);

    expect($result->isSuccess())->toBeTrue();
    expect($result->payload?->externalId)->toBe('z50100-dro');
    expect($result->payload?->name)->toBe('Станок токарный Metal Master Z 50100 DRO');
    expect($result->payload?->brand)->toBe('MetalMaster');
    expect($result->payload?->priceAmount)->toBe(1049972);
    expect($result->payload?->currency)->toBe('RUB');
    expect($result->payload?->qty)->toBe(7);
    expect($result->payload?->inStock)->toBeTrue();
    expect($result->payload?->source['bucket'] ?? null)->toBe('promyshlennye');
    expect($result->payload?->source['slug'] ?? null)->toBe('z50100-dro');
});

it('returns fatal error for invalid record type in metalmaster adapter', function () {
    $result = (new MetalmasterSupplierAdapter)->mapRecord(['invalid']);

    expect($result->isSuccess())->toBeFalse();
    expect($result->hasFatalError())->toBeTrue();
    expect($result->errors[0]->code)->toBe('invalid_record_type');
    expect($result->errors[0]->level)->toBe(ImportErrorLevel::Fatal);
});
