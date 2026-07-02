<?php

use App\Support\CatalogImport\Suppliers\Stalex\StalexSupplierAdapter;
use App\Support\CatalogImport\Yml\YmlOfferRecord;

function stalexOffer(string $id, string $innerXml): YmlOfferRecord
{
    return new YmlOfferRecord(
        id: $id,
        type: null,
        available: true,
        categoryId: 100,
        xml: <<<XML
        <offer id="{$id}" available="true">
            <categoryId>100</categoryId>
            <currencyId>RUB</currencyId>
            <price>1000</price>
            {$innerXml}
        </offer>
        XML,
    );
}

it('appends gallery from Фотогалерея param after the main picture', function () {
    $offer = stalexOffer('1', <<<'XML'
        <name>Станок</name>
        <picture>https://www.stalex.ru/upload/iblock/6de/main.jpg</picture>
        <param name="Фотогалерея">https://www.stalex.ru/upload/iblock/a/g1.jpg, https://www.stalex.ru/upload/iblock/b/g2.jpg</param>
    XML);

    $result = (new StalexSupplierAdapter)->mapRecord($offer);

    expect($result->payload)->not->toBeNull();
    expect($result->payload?->images)->toBe([
        'https://www.stalex.ru/upload/iblock/6de/main.jpg',
        'https://www.stalex.ru/upload/iblock/a/g1.jpg',
        'https://www.stalex.ru/upload/iblock/b/g2.jpg',
    ]);
});

it('dedupes the main picture that reappears in the gallery under a different path/hash', function () {
    // docs §4 п.7: same file (DSC08350 bl.jpg), different iblock hash, and %20 vs space.
    $offer = stalexOffer('70', <<<'XML'
        <name>3-IN-1</name>
        <picture>https://www.stalex.ru/upload/iblock/66b/aaa/DSC08350%20bl.jpg</picture>
        <param name="Фотогалерея">https://www.stalex.ru/upload/iblock/ac7/bbb/DSC08350%20bl.jpg, https://www.stalex.ru/upload/iblock/5f4/ccc/DSC08355%20bl.jpg</param>
    XML);

    $result = (new StalexSupplierAdapter)->mapRecord($offer);

    // The duplicate (DSC08350 bl.jpg) is dropped; %20 in surviving URLs is preserved as-is.
    expect($result->payload?->images)->toBe([
        'https://www.stalex.ru/upload/iblock/66b/aaa/DSC08350%20bl.jpg',
        'https://www.stalex.ru/upload/iblock/5f4/ccc/DSC08355%20bl.jpg',
    ]);
});

it('keeps only the main picture when the gallery param is empty', function () {
    $offer = stalexOffer('39', <<<'XML'
        <name>Без галереи</name>
        <picture>https://www.stalex.ru/upload/iblock/6bb/only.jpg</picture>
        <param name="Фотогалерея"></param>
    XML);

    $result = (new StalexSupplierAdapter)->mapRecord($offer);

    expect($result->payload?->images)->toBe([
        'https://www.stalex.ru/upload/iblock/6bb/only.jpg',
    ]);
});

it('keeps only the main picture when the gallery param is absent', function () {
    $offer = stalexOffer('84', <<<'XML'
        <name>Без параметра</name>
        <picture>https://www.stalex.ru/upload/iblock/ab0/only.jpg</picture>
    XML);

    $result = (new StalexSupplierAdapter)->mapRecord($offer);

    expect($result->payload?->images)->toBe([
        'https://www.stalex.ru/upload/iblock/ab0/only.jpg',
    ]);
});

it('strips #BLOCK...# CMS placeholders from the description', function () {
    $offer = stalexOffer('5', <<<'XML'
        <name>С мусором</name>
        <picture>https://www.stalex.ru/upload/iblock/x/main.jpg</picture>
        <description><![CDATA[Полезный текст. #BLOCK_1# Ещё текст. #BLOCK_SLIDER_1#]]></description>
    XML);

    $result = (new StalexSupplierAdapter)->mapRecord($offer);

    $description = (string) $result->payload?->description;

    expect($description)->not->toContain('#BLOCK');
    expect($description)->toContain('Полезный текст.');
    expect($description)->toContain('Ещё текст.');
});
