<?php

use App\Support\CatalogImport\Suppliers\Metaltec\MetaltecSupplierProfile;
use App\Support\Metaltec\MetaltecProductImportService;
use Tests\TestCase;

uses(TestCase::class);

it('processes metaltec xml feed in dry-run mode', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ItemsData>
    <Items>
        <Item>
            <ID>A1</ID>
            <Наименование>MetalTec Sample One</Наименование>
            <Раздел>Фрезерные станки</Раздел>
            <ЦенаРуб>1500000</ЦенаРуб>
            <Валюта>CNY</Валюта>
            <Статус>В наличии</Статус>
            <Фото>https://metaltec.com.ru/upload/a1.jpg</Фото>
            <Характеристики><![CDATA[
                <div class="productMain__characteristics">
                    <span class="productMain__list-title">Технические характеристики</span>
                    <ul class="productMain__list">
                        <li class="productMain__list-item">
                            <div><span>Мощность</span></div>
                            <div><b>7.5 кВт</b></div>
                        </li>
                    </ul>
                </div>
            ]]></Характеристики>
        </Item>
        <Item>
            <ID>A2</ID>
            <Наименование>MetalTec Sample Two</Наименование>
            <Раздел>Токарные станки</Раздел>
            <Валюта>CNY</Валюта>
            <Статус>Под заказ</Статус>
            <Фото>https://metaltec.com.ru/upload/a2.jpg</Фото>
            <Характеристики><![CDATA[
                <div class="productMain__characteristics">
                    <span class="productMain__list-title">Технические характеристики</span>
                    <ul class="productMain__list">
                        <li class="productMain__list-item">
                            <div><span>Вес</span></div>
                            <div><b>4800 кг</b></div>
                        </li>
                    </ul>
                </div>
            ]]></Характеристики>
        </Item>
    </Items>
</ItemsData>
XML;

    $path = tempnam(sys_get_temp_dir(), 'metaltec_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(MetaltecProductImportService::class)->run([
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
        expect($result['samples'][0]['price'])->toBe('1500000');
        expect($result['samples'][0]['currency'])->toBe('RUB');
        expect($result['samples'][1]['external_id'])->toBe('A2');
        expect($result['samples'][1]['price'])->toBe('0');
        expect($result['samples'][1]['section'])->toBe('Токарные станки');
    } finally {
        @unlink($path);
    }
});

it('lists metaltec categories from section field', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ItemsData>
    <Items>
        <Item>
            <ID>A1</ID>
            <Наименование>MetalTec Sample One</Наименование>
            <Раздел>Фрезерные станки</Раздел>
        </Item>
        <Item>
            <ID>A2</ID>
            <Наименование>MetalTec Sample Two</Наименование>
            <Раздел>Токарные станки</Раздел>
        </Item>
        <Item>
            <ID>A3</ID>
            <Наименование>MetalTec Sample Three</Наименование>
            <Раздел>Фрезерные станки</Раздел>
        </Item>
    </Items>
</ItemsData>
XML;

    $path = tempnam(sys_get_temp_dir(), 'metaltec_feed_');
    file_put_contents($path, $xml);

    try {
        $profile = new MetaltecSupplierProfile;
        $categories = app(MetaltecProductImportService::class)->listCategoryNodes([
            'source' => $path,
        ]);

        expect($categories)->toBe([
            [
                'id' => $profile->categoryIdForSection('Фрезерные станки'),
                'name' => 'Фрезерные станки',
                'parent_id' => null,
            ],
            [
                'id' => $profile->categoryIdForSection('Токарные станки'),
                'name' => 'Токарные станки',
                'parent_id' => null,
            ],
        ]);
    } finally {
        @unlink($path);
    }
});

it('filters metaltec xml feed by selected section category', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ItemsData>
    <Items>
        <Item>
            <ID>A1</ID>
            <Наименование>MetalTec Sample One</Наименование>
            <Раздел>Фрезерные станки</Раздел>
            <ЦенаРуб>1500000</ЦенаРуб>
            <Статус>В наличии</Статус>
            <Фото>https://metaltec.com.ru/upload/a1.jpg</Фото>
            <Характеристики><![CDATA[<div class="productMain__characteristics"></div>]]></Характеристики>
        </Item>
        <Item>
            <ID>A2</ID>
            <Наименование>MetalTec Sample Two</Наименование>
            <Раздел>Токарные станки</Раздел>
            <Статус>Под заказ</Статус>
            <Фото>https://metaltec.com.ru/upload/a2.jpg</Фото>
            <Характеристики><![CDATA[<div class="productMain__characteristics"></div>]]></Характеристики>
        </Item>
    </Items>
</ItemsData>
XML;

    $path = tempnam(sys_get_temp_dir(), 'metaltec_feed_');
    file_put_contents($path, $xml);

    try {
        $profile = new MetaltecSupplierProfile;
        $result = app(MetaltecProductImportService::class)->run([
            'source' => $path,
            'write' => false,
            'category_id' => $profile->categoryIdForSection('Токарные станки'),
            'show_samples' => 2,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(1);
        expect($result['processed'])->toBe(1);
        expect($result['errors'])->toBe(0);
        expect($result['samples'])->toHaveCount(1);
        expect($result['samples'][0]['external_id'])->toBe('A2');
        expect($result['samples'][0]['section'])->toBe('Токарные станки');
    } finally {
        @unlink($path);
    }
});
