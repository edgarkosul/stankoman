<?php

use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use Tests\TestCase;

uses(TestCase::class);

it('processes yandex market feed in dry-run mode', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
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
        <price>999</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 2,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(2);
        expect($result['processed'])->toBe(2);
        expect($result['errors'])->toBe(0);
        expect($result['success'])->toBeTrue();
        expect($result['samples'])->toHaveCount(2);
        expect($result['samples'][0]['external_id'])->toBe('A1');
        expect($result['samples'][1]['offer_type'])->toBe('vendor.model');
    } finally {
        @unlink($path);
    }
});

it('returns record-level error for invalid yandex market feed offer', function () {
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

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 3,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(1);
        expect($result['processed'])->toBe(0);
        expect($result['errors'])->toBe(1);
        expect($result['success'])->toBeFalse();
        expect($result['url_errors'])->toHaveCount(1);
        expect($result['url_errors'][0]['url'])->toBe('offer:A2');
        expect($result['url_errors'][0]['message'])->toContain('required field');
    } finally {
        @unlink($path);
    }
});

it('maps download_images flag into processor download_media option', function () {
    $service = app(YandexMarketFeedImportService::class);

    $normalizeOptions = new ReflectionMethod(YandexMarketFeedImportService::class, 'normalizeOptions');
    $normalizeOptions->setAccessible(true);
    $processorOptions = new ReflectionMethod(YandexMarketFeedImportService::class, 'processorOptions');
    $processorOptions->setAccessible(true);

    $normalized = $normalizeOptions->invoke($service, [
        'source' => 'https://example.test/yml.xml',
        'download_images' => true,
        'run_id' => 77,
    ]);
    $options = $processorOptions->invoke($service, $normalized);

    expect($options['download_media'] ?? null)->toBeTrue();
    expect($options['run_id'] ?? null)->toBe(77);
});

it('reads categories from yandex market feed', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="22">Компрессоры</category>
      <category id="31" parentId="22">Пылесосы</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>22</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $categoryNodes = app(YandexMarketFeedImportService::class)->listCategoryNodes([
            'source' => $path,
        ]);
        $categories = app(YandexMarketFeedImportService::class)->listCategories([
            'source' => $path,
        ]);

        expect($categoryNodes)->toBe([
            22 => ['id' => 22, 'name' => 'Компрессоры', 'parent_id' => null],
            31 => ['id' => 31, 'name' => 'Пылесосы', 'parent_id' => 22],
        ]);
        expect($categories)->toBe([
            22 => 'Компрессоры',
            31 => 'Пылесосы',
        ]);
    } finally {
        @unlink($path);
    }
});

it('filters yandex market feed import by selected category including descendants', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="22">Root 22</category>
      <category id="31" parentId="22">Child 31</category>
      <category id="44">Other 44</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Category 22 product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>22</categoryId>
      </offer>
      <offer id="A2" available="true">
        <name>Category 31 product</name>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <categoryId>31</categoryId>
      </offer>
      <offer id="A3" available="true">
        <name>Category 44 product</name>
        <price>777</price>
        <currencyId>RUB</currencyId>
        <categoryId>44</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'category_id' => 22,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 3,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(2);
        expect($result['processed'])->toBe(2);
        expect($result['errors'])->toBe(0);
        expect($result['success'])->toBeTrue();
        expect($result['samples'])->toHaveCount(2);
        expect($result['samples'][0]['external_id'])->toBe('A1');
        expect($result['samples'][1]['external_id'])->toBe('A2');
    } finally {
        @unlink($path);
    }
});
