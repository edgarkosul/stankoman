<?php

use App\Models\Product;
use App\Support\Products\ProductSpecs;
use Tests\TestCase;

uses(TestCase::class);

function productWithSpecs(mixed $specs): Product
{
    $product = new Product;
    $product->specs = $specs;

    return $product;
}

it('reads the list format written by imports', function (): void {
    $rows = (new ProductSpecs)->rows(productWithSpecs([
        ['name' => 'Мощность', 'value' => '2.2 кВт', 'source' => 'yml'],
        ['name' => 'Напряжение', 'value' => '380 В'],
    ]));

    expect($rows)->toBe([
        ['name' => 'Мощность', 'value' => '2.2 кВт', 'source' => 'yml'],
        ['name' => 'Напряжение', 'value' => '380 В', 'source' => null],
    ]);
});

it('reads the plain map format written by hand', function (): void {
    $rows = (new ProductSpecs)->rows(productWithSpecs([
        'Мощность' => '2.2 кВт',
        'Наличие ресивера' => true,
        'Осушитель' => false,
    ]));

    // Булево из фида читается человеком только словами.
    expect($rows)->toBe([
        ['name' => 'Мощность', 'value' => '2.2 кВт', 'source' => null],
        ['name' => 'Наличие ресивера', 'value' => 'Да', 'source' => null],
        ['name' => 'Осушитель', 'value' => 'Нет', 'source' => null],
    ]);
});

it('still reads specs left in the column as a json string', function (): void {
    $rows = (new ProductSpecs)->rows(productWithSpecs('[{"name":"Вес","value":"120 кг"}]'));

    expect($rows)->toBe([
        ['name' => 'Вес', 'value' => '120 кг', 'source' => null],
    ]);
});

it('drops rows that would render as an empty cell', function (): void {
    $rows = (new ProductSpecs)->rows(productWithSpecs([
        ['name' => 'Мощность', 'value' => '  2.2 кВт  '],   // пробелы срезаются
        ['name' => '   ', 'value' => '380 В'],              // название пустое
        ['name' => 'Гарантия', 'value' => null],            // значения нет
        ['name' => 'Комплект', 'value' => ['шланг', 'ключ']], // массив вместо значения
        ['name' => 'Класс', 'value' => 0],                  // ноль — это значение
    ]));

    expect($rows)->toBe([
        ['name' => 'Мощность', 'value' => '2.2 кВт', 'source' => null],
        ['name' => 'Класс', 'value' => '0', 'source' => null],
    ]);
});

it('returns nothing when the column holds no specs', function (): void {
    expect((new ProductSpecs)->rows(productWithSpecs(null)))->toBe([])
        ->and((new ProductSpecs)->rows(productWithSpecs('не json')))->toBe([])
        ->and((new ProductSpecs)->rows(productWithSpecs([])))->toBe([]);
});

it('cuts the list for callers that pay for length', function (): void {
    $product = productWithSpecs([
        'Мощность' => '2.2 кВт',
        'Напряжение' => '380 В',
        'Вес' => '120 кг',
    ]);

    expect((new ProductSpecs)->rows($product, 2))->toHaveCount(2)
        ->and((new ProductSpecs)->rows($product, 2)[1]['name'])->toBe('Напряжение')
        // 0 — это «все», а не «ни одной».
        ->and((new ProductSpecs)->rows($product, 0))->toHaveCount(3);
});

it('counts only rows that survived normalization when cutting', function (): void {
    // Обрезка идёт по годным строкам: иначе лимит в две строки мог бы
    // вернуть одну, потому что вторую выбросили как мусор.
    $rows = (new ProductSpecs)->rows(productWithSpecs([
        ['name' => 'Мусор', 'value' => null],
        ['name' => 'Мощность', 'value' => '2.2 кВт'],
        ['name' => 'Вес', 'value' => '120 кг'],
        ['name' => 'Напряжение', 'value' => '380 В'],
    ]), 2);

    expect($rows)->toBe([
        ['name' => 'Мощность', 'value' => '2.2 кВт', 'source' => null],
        ['name' => 'Вес', 'value' => '120 кг', 'source' => null],
    ]);
});
