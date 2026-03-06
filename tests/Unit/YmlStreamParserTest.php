<?php

use App\Support\CatalogImport\Yml\YandexMarketFeedAdapter;
use App\Support\CatalogImport\Yml\YmlStreamParser;

it('streams categories and offers from yml feed', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <name>Test</name>
    <company>Test</company>
    <url>https://example.test</url>
    <currencies>
      <currency id="RUB" rate="1"/>
    </currencies>
    <categories>
      <category id="1">Category 1</category>
      <category id="2">Category 2</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" type="vendor.model" available="false">
        <typePrefix>Пылесос</typePrefix>
        <vendor>Vactool</vendor>
        <model>VT-9000</model>
        <categoryId>2</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);

        expect($stream->categories)->toBe([
            1 => 'Category 1',
            2 => 'Category 2',
        ]);

        $offers = iterator_to_array($stream->offers);

        expect($offers)->toHaveCount(2);

        expect($offers[0]->id)->toBe('A1');
        expect($offers[0]->type)->toBeNull();
        expect($offers[0]->available)->toBeTrue();
        expect($offers[0]->categoryId)->toBe(1);

        expect($offers[1]->id)->toBe('A2');
        expect($offers[1]->type)->toBe('vendor.model');
        expect($offers[1]->available)->toBeFalse();
        expect($offers[1]->categoryId)->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('maps simplified and vendor.model offers into product payloads', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="1">Category 1</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <description>Simple description.</description>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <param name="Мощность" unit="Вт">1200</param>
        <param name="Цвет">  Красный </param>
        <param name="Цвет">Красный</param>
        <param name="">ignored</param>
        <picture>https://example.test/images/simple-1.jpg</picture>
        <picture>https://example.test/images/simple-2.jpg</picture>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" type="vendor.model" available="false">
        <typePrefix>Пылесос</typePrefix>
        <vendor>Vactool</vendor>
        <model>VT-9000</model>
        <description>Vendor model description.</description>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <param name="Напряжение" unit="В">220</param>
        <param name="Комплектация">Шланг</param>
        <picture>https://example.test/images/vm-1.jpg</picture>
        <picture>
          https://example.test/images/vm-2.jpg
        </picture>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $adapter = new YandexMarketFeedAdapter;

        $simple = $adapter->mapOffer($offers[0]);
        expect($simple->isSuccess())->toBeTrue();
        expect($simple->payload?->externalId)->toBe('A1');
        expect($simple->payload?->name)->toBe('Simple Product');
        expect($simple->payload?->description)->toBe('Simple description.');
        expect($simple->payload?->priceAmount)->toBe(123);
        expect($simple->payload?->currency)->toBe('RUB');
        expect($simple->payload?->inStock)->toBeTrue();
        expect($simple->payload?->images)->toBe([
            'https://example.test/images/simple-1.jpg',
            'https://example.test/images/simple-2.jpg',
        ]);
        expect($simple->payload?->attributes)->toBe([
            ['name' => 'Мощность', 'value' => '1200 Вт', 'source' => 'yml'],
            ['name' => 'Цвет', 'value' => 'Красный', 'source' => 'yml'],
        ]);

        $vendorModel = $adapter->mapOffer($offers[1]);
        expect($vendorModel->isSuccess())->toBeTrue();
        expect($vendorModel->payload?->externalId)->toBe('A2');
        expect($vendorModel->payload?->name)->toBe('Пылесос Vactool VT-9000');
        expect($vendorModel->payload?->description)->toBe('Vendor model description.');
        expect($vendorModel->payload?->brand)->toBe('Vactool');
        expect($vendorModel->payload?->priceAmount)->toBe(999);
        expect($vendorModel->payload?->currency)->toBe('RUB');
        expect($vendorModel->payload?->inStock)->toBeFalse();
        expect($vendorModel->payload?->images)->toBe([
            'https://example.test/images/vm-1.jpg',
            'https://example.test/images/vm-2.jpg',
        ]);
        expect($vendorModel->payload?->attributes)->toBe([
            ['name' => 'Напряжение', 'value' => '220 В', 'source' => 'yml'],
            ['name' => 'Комплектация', 'value' => 'Шланг', 'source' => 'yml'],
        ]);
    } finally {
        @unlink($path);
    }
});

it('skips vendor.model offers when required fields are missing', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A2" type="vendor.model" available="true">
        <typePrefix>Пылесос</typePrefix>
        <model>VT-9000</model>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $adapter = new YandexMarketFeedAdapter;
        $result = $adapter->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeFalse();
        expect($result->payload)->toBeNull();
        expect(collect($result->errors)->pluck('code')->all())->toContain('missing_required_vendor');
    } finally {
        @unlink($path);
    }
});

it('skips simplified offers when <name> is missing', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yml_');
    file_put_contents($path, $xml);

    try {
        $stream = (new YmlStreamParser)->open($path);
        $offers = iterator_to_array($stream->offers);

        $adapter = new YandexMarketFeedAdapter;
        $result = $adapter->mapOffer($offers[0]);

        expect($result->isSuccess())->toBeFalse();
        expect($result->payload)->toBeNull();
        expect(collect($result->errors)->pluck('code')->all())->toContain('missing_required_name');
    } finally {
        @unlink($path);
    }
});
