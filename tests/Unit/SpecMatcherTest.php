<?php

use App\Services\Ai\Data\SpecCondition;
use App\Services\Catalog\SpecMatcher;

/*
 * Разбор строк характеристик для подбора по порогу.
 *
 * Все записи ниже — настоящие, из карточек каталога (дев, 09.10.2026):
 * у каждого поставщика своя манера, и каждая уже ломала бы наивное
 * сравнение.
 */

it('достаёт числа без углов и без цифр единицы', function (string $value, array $numbers): void {
    expect(SpecMatcher::numbers($value))->toBe($numbers);
})->with([
    'круг и прямоугольник Metal Master' => ['400 / 550х360', [400.0, 550.0, 360.0]],
    'угол перед размером MetMachine' => ['90° - 500х500 мм', [500.0, 500.0]],
    'круг, квадрат и прямоугольник REALREZ через табуляцию' => ["400\t400х400\t400х500", [400.0, 400.0, 400.0, 400.0, 500.0]],
    'тысячи через пробел' => ['15 000', [15000.0]],
    'тысячи с единицей' => ['10 000 об /мин', [10000.0]],
    'диапазон тока' => ['50 - 175, А', [50.0, 175.0]],
    'десятичная запятая и куб' => ['1,5 м3/мин', [1.5]],
    'только углы' => ['0°-90°', []],
    'числа нет' => ['мешки л', []],
]);

it('единицу берёт из значения, а потом из хвоста названия', function (string $name, string $value, ?string $unit): void {
    expect(SpecMatcher::unitOfRow($name, $value))->toBe($unit);
})->with([
    ['Объем бака', '60 л', 'л'],
    ['Объем воздушного потока', '570 м³/ч', 'м3/ч'],
    ['Сварочный ток', '50 - 175, А', 'а'],
    ['Производитель­ность, л/мин', '1090', 'л/мин'],
    ['Производительность: л/мин', '420', 'л/мин'],
    ['Мощность двигателя (кВт)', '2.2/2.8', 'квт'],
    ['Грузоподьемность, т', '100', 'т'],
    // «в» и «а» в середине названия — предлог и союз, а не вольты с амперами.
    ['Производительность в час', '500', null],
]);

it('понимает единицу покупателя и не путает величины', function (): void {
    expect(SpecMatcher::unit('м³/мин'))->toBe('м3/мин')
        ->and(SpecMatcher::unit('тонн'))->toBe('т')
        ->and(SpecMatcher::unit('кубов в минуту'))->toBeNull()
        ->and(SpecMatcher::factor('л/мин', 'м3/мин'))->toBe(0.001)
        ->and(SpecMatcher::factor('вт', 'квт'))->toBe(0.001)
        ->and(SpecMatcher::factor('м2/ч', 'л/мин'))->toBeNull();
});

it('находит строку по основе слова, несмотря на мусор поставщиков', function (string $name, array $variants): void {
    expect(SpecMatcher::nameMatches($name, $variants))->toBeTrue();
})->with([
    'мягкий перенос внутри слова' => ['Производитель­ность, л/мин', ['производительность']],
    'мягкий знак вместо твёрдого' => ['Грузоподьемность, т', ['грузоподъёмность']],
    'градус знаком «˚»' => ['Диаметр резки под 90˚, мм', ['диаметр резки 90°']],
    'второй вариант из нескольких' => ['Длина штока, мм', ['ход штока', 'длина штока']],
]);

it('проверяет порог в единицах покупателя', function (): void {
    $matcher = new SpecMatcher;
    $flow = new SpecCondition(['производительность'], min: 3, unit: 'м³/мин');

    expect($matcher->check($flow, [['name' => 'Производитель­ность, л/мин', 'value' => '3600']])['status'])->toBe('pass')
        ->and($matcher->check($flow, [['name' => 'Производительность, л/мин', 'value' => '2900']])['status'])->toBe('fail')
        // Строки нет — это «не проверить», а не «не подошёл».
        ->and($matcher->check($flow, [['name' => 'Давление, бар', 'value' => '10']])['status'])->toBe('nodata');
});

it('«каждое число» отсеивает станок, который берёт круг 400, но не квадрат 400', function (): void {
    $matcher = new SpecMatcher;
    $square = new SpecCondition(['90°'], min: 400, unit: 'мм', everyNumber: true);
    $loose = new SpecCondition(['90°'], min: 400, unit: 'мм');
    $bsm = [['name' => '90° (мм)', 'value' => '400 / 550х360']];
    $mgh = [['name' => '90° (мм)', 'value' => '400 / 400×400']];

    expect($matcher->check($square, $bsm)['status'])->toBe('fail')
        ->and($matcher->check($square, $mgh)['status'])->toBe('pass')
        ->and($matcher->check($loose, $bsm)['status'])->toBe('pass');
});

it('меряет, насколько не дотянуло, — для ближайших к порогу', function (): void {
    $jack = new SpecCondition(['грузоподъемность'], min: 1000, unit: 'т');

    expect(SpecMatcher::distance($jack, [300.0]))->toBe(700.0)
        ->and(SpecMatcher::distance($jack, [200.0, 300.0]))->toBe(700.0)
        ->and(SpecMatcher::distance(new SpecCondition(['вес'], max: 50), [35.0, 40.0]))->toBe(0.0)
        ->and(SpecMatcher::distance($jack, []))->toBeNull();
});
