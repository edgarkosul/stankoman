<?php

use App\Services\Catalog\CatalogQueryShape;

/*
 * Форма запроса решает единственное: сколько смысла подмешивать
 * в гибридный поиск. Ошибка здесь дорогая в обе стороны — замер
 * 04.09.2026: на «ВК-J 15/10» смысл 0.8 теряет точную модель совсем,
 * а на «компрессор для гаража» смысл 0.3 уводит выдачу в промывочные
 * компрессоры и шланги.
 */

it('узнаёт модель и артикул', function (string $query): void {
    expect(CatalogQueryShape::looksLikeModel($query))->toBeTrue();
})->with([
    'ВК-J 15/10',
    'VK41001510',
    'WDK-DUSTER EP+',
    'AC-400',
    'DM-7500B',
    'Metal Master BSM-115',
    '39000903',
]);

it('не считает моделью описание задачи', function (string $query): void {
    expect(CatalogQueryShape::looksLikeModel($query))->toBeFalse();
})->with([
    'на дачу пол пылесосить',
    'компрессор для гаража',
    // Цифра есть, но это бюджет, а не модель — и слов слишком много.
    'компрессор до 30 тысяч для гаража',
    // Характеристика, а не артикул: 2200 короче пятизначного порога.
    'пылесос 2200 вт',
    'чем убрать стружку возле станка',
    '',
]);

it('не принимает за артикул мощность и напряжение', function (): void {
    // «380», «220», «2200» — это вольты и ватты, они в запросах обычны,
    // и выключать на них смысл было бы вредно.
    expect(CatalogQueryShape::looksLikeModel('380'))->toBeFalse()
        ->and(CatalogQueryShape::looksLikeModel('2200'))->toBeFalse()
        ->and(CatalogQueryShape::looksLikeModel('100'))->toBeFalse();
});

/*
 * Сверка обозначения с товаром. Появилась 04.09.2026 после замера: бот
 * подставлял в `get_product` то, что услышал от покупателя, — «ВК-J 15/10
 * TG», — и получал «товар не найден» о компрессоре за 525 920 ₽,
 * стоящем на складе.
 */

it('видит одно обозначение в трёх записях покупателя', function (string $written): void {
    expect(CatalogQueryShape::core($written))->toBe('вкj1510tg');
})->with([
    'ВК-J 15/10 TG',
    'ВК J15/10TG',
    'вк-j 15/10 tg',
    '  ВК-J  15/10  TG  ',
]);

it('находит товар по обозначению внутри длинного названия', function (): void {
    $name = 'Компрессор винтовой ВедКом ВК-J 15/10 TG с частотным преобразователем';

    expect(CatalogQueryShape::namesProduct('ВК-J 15/10 TG', $name, 'VK41001510'))->toBeTrue()
        ->and(CatalogQueryShape::namesProduct('ВедКом ВК-J 15/10 TG', $name, 'VK41001510'))->toBeTrue()
        ->and(CatalogQueryShape::namesProduct('VK41001510', $name, 'VK41001510'))->toBeTrue();
});

it('не путает соседние модели одной серии', function (): void {
    // Разница в одну цифру — это другая машина, другие деньги и другие
    // задачи. Отдать «похожее» здесь хуже, чем не найти ничего: бот
    // назовёт цену и наличие чужого товара, и покупатель об этом не узнает.
    $name = 'Компрессор винтовой ВедКом ВК-J 15/8 TG с частотным преобразователем';

    expect(CatalogQueryShape::namesProduct('ВК-J 15/10 TG', $name, 'VK41001508'))->toBeFalse();
});

it('не принимает за обозначение короткий хвост', function (string $query): void {
    // «TG» и «15» найдутся в половине каталога.
    expect(CatalogQueryShape::namesProduct($query, 'Компрессор ВК-J 15/10 TG', 'VK41001510'))->toBeFalse();
})->with(['TG', '15', '', '  ']);
