<?php

use App\Support\CatalogImport\Enums\ImportErrorLevel;
use App\Support\CatalogImport\Suppliers\Metaltec\MetaltecSupplierAdapter;
use App\Support\CatalogImport\Suppliers\Metaltec\MetaltecSupplierProfile;
use App\Support\CatalogImport\Xml\XmlRecord;

it('maps metaltec xml record into product payload', function () {
    $record = new XmlRecord(
        index: 0,
        nodeName: 'Item',
        attributes: [],
        xml: <<<'XML'
<Item>
    <ID>469</ID>
    <Наименование>MetalTec TOPMILL 850S Вертикальный обрабатывающий центр с ЧПУ</Наименование>
    <Раздел>Фрезерные обрабатывающие центры с ЧПУ</Раздел>
    <Цена>405000</Цена>
    <СтараяЦена>425000</СтараяЦена>
    <ЦенаРуб>4718412</ЦенаРуб>
    <СтараяЦенаРуб>4951420</СтараяЦенаРуб>
    <Валюта>CNY</Валюта>
    <Статус>В наличии</Статус>
    <Фото>https://metaltec.com.ru/upload/main.jpg</Фото>
    <ФотоДоп>https://metaltec.com.ru/upload/extra-1.jpg, /upload/extra-2.jpg</ФотоДоп>
    <Анонс>Система управления : Siemens &lt;br&gt; Размеры рабочего стола : 1000 х 500 мм</Анонс>
    <Описание><![CDATA[<p><strong>Описание</strong> товара</p>]]></Описание>
    <КонструктивныеОсобенности><![CDATA[
        <table>
            <tr>
                <td><a href="/upload/doc.pdf">PDF</a></td>
                <td><img src="/upload/feature.jpg"></td>
            </tr>
        </table>
    ]]></КонструктивныеОсобенности>
    <Характеристики><![CDATA[
        <div class="productMain__characteristics">
            <span class="productMain__list-title">Технические характеристики</span>
            <ul class="productMain__list">
                <li class="productMain__list-item">
                    <div><span>Модель</span></div>
                    <div><b>TOPMILL 850S</b></div>
                </li>
                <li class="productMain__list-item">
                    <div><span>Размеры рабочего стола, мм</span></div>
                    <div><b>1000 х 500</b></div>
                </li>
            </ul>
        </div>
    ]]></Характеристики>
    <ОбщийВес>5500 кг</ОбщийВес>
</Item>
XML,
    );

    $result = (new MetaltecSupplierAdapter)->mapRecord($record);

    expect($result->isSuccess())->toBeTrue();
    expect($result->payload?->externalId)->toBe('469');
    expect($result->payload?->name)->toBe('MetalTec TOPMILL 850S Вертикальный обрабатывающий центр с ЧПУ');
    expect($result->payload?->brand)->toBe('Metaltec');
    expect($result->payload?->priceAmount)->toBe(4951420);
    expect($result->payload?->discountPrice)->toBe(4718412);
    expect($result->payload?->currency)->toBe('RUB');
    expect($result->payload?->inStock)->toBeTrue();
    expect($result->payload?->qty)->toBe(1);
    expect($result->payload?->images)->toBe([
        'https://metaltec.com.ru/upload/main.jpg',
        'https://metaltec.com.ru/upload/extra-1.jpg',
        'https://metaltec.com.ru/upload/extra-2.jpg',
    ]);
    expect($result->payload?->short)->toBe('Система управления : Siemens; Размеры рабочего стола : 1000 х 500 мм');
    expect($result->payload?->description)->toContain('<p><strong>Описание</strong> товара</p>');
    expect($result->payload?->description)->toContain('https://metaltec.com.ru/upload/doc.pdf');
    expect($result->payload?->description)->toContain('https://metaltec.com.ru/upload/feature.jpg');
    expect($result->payload?->extraDescription)->toBeNull();
    expect($result->payload?->source['source_currency'] ?? null)->toBe('CNY');
    expect($result->payload?->source['section'] ?? null)->toBe('Фрезерные обрабатывающие центры с ЧПУ');
    expect($result->payload?->source['category_id'] ?? null)
        ->toBe((new MetaltecSupplierProfile)->categoryIdForSection('Фрезерные обрабатывающие центры с ЧПУ'));
    expect(collect($result->payload?->attributes ?? [])->pluck('name')->all())->toContain(
        'Модель',
        'Размеры рабочего стола, мм',
        'Раздел',
        'Общий вес',
    );
});

it('maps empty status and missing price into false stock and null pricing', function () {
    $record = new XmlRecord(
        index: 0,
        nodeName: 'Item',
        attributes: [],
        xml: <<<'XML'
<Item>
    <ID>12533</ID>
    <Наименование>Токарные обрабатывающие центры MetalTec TC 70</Наименование>
    <Раздел>Токарные обрабатывающие центры</Раздел>
    <Валюта>CNY</Валюта>
    <Статус></Статус>
    <Фото>https://metaltec.com.ru/upload/main.jpg</Фото>
    <Характеристики><![CDATA[<div class="productMain__characteristics"></div>]]></Характеристики>
</Item>
XML,
    );

    $result = (new MetaltecSupplierAdapter)->mapRecord($record);

    expect($result->isSuccess())->toBeTrue();
    expect($result->payload?->priceAmount)->toBeNull();
    expect($result->payload?->discountPrice)->toBeNull();
    expect($result->payload?->currency)->toBeNull();
    expect($result->payload?->inStock)->toBeFalse();
    expect($result->payload?->qty)->toBe(0);
});

it('returns fatal error for invalid record type in metaltec adapter', function () {
    $result = (new MetaltecSupplierAdapter)->mapRecord(['invalid']);

    expect($result->isSuccess())->toBeFalse();
    expect($result->hasFatalError())->toBeTrue();
    expect($result->errors[0]->code)->toBe('invalid_record_type');
    expect($result->errors[0]->level)->toBe(ImportErrorLevel::Fatal);
});
