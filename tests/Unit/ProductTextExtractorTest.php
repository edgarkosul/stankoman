<?php

use App\Services\Ai\Support\ProductTextExtractor;

/*
 * Разбор описания товара — чистая функция над разметкой, и проверяется
 * без базы. Проверять есть что: характеристики в каталоге лежат ТАБЛИЦЕЙ
 * (замер 03.09.2026 — td и tr вдвое частотнее p), и именно на ней
 * ошибается strip_tags, ради которого этот класс и написан.
 */

function extractor(): ProductTextExtractor
{
    return new ProductTextExtractor;
}

it('разбирает таблицу характеристик в «название: значение»', function (): void {
    // Ровно та разметка, что лежит в описаниях каталога.
    $html = '<table><tbody>'
        .'<tr><td> Напряжение сети</td><td> 220 В</td></tr>'
        .'<tr><td> Объем ресивера</td><td> 100 л</td></tr>'
        .'</tbody></table>';

    expect(extractor()->toText($html))->toBe("Напряжение сети: 220 В\nОбъем ресивера: 100 л");
});

it('не склеивает строки таблицы в одну — на этом ломается strip_tags', function (): void {
    // «Напряжение сети 220 В Объем ресивера 100 л» — строка, в которой
    // конец значения и начало следующего названия ничем не разделены;
    // модель на таком путает столбцы соседних строк.
    $html = '<table><tr><td>Мощность</td><td>2,2 кВт</td></tr><tr><td>Вес</td><td>70 кг</td></tr></table>';

    expect(extractor()->toText($html))->toContain("2,2 кВт\nВес");
});

it('разделяет вертикальной чертой строку из трёх и более ячеек', function (): void {
    $html = '<table><tr><td>Габариты</td><td>1090</td><td>430</td><td>820</td></tr></table>';

    expect(extractor()->toText($html))->toBe('Габариты | 1090 | 430 | 820');
});

it('сохраняет абзацы и списки отдельными строками', function (): void {
    $html = '<p>Первый абзац.</p><ul><li>Фильтр</li><li>Шланги 2 шт</li></ul><p>Второй абзац.</p>';

    expect(extractor()->toText($html))->toBe("Первый абзац.\nФильтр\nШланги 2 шт\nВторой абзац.");
});

it('снимает строчную разметку, не разрывая фразу', function (): void {
    $html = '<p><strong>Aurora CYCLON-100</strong> отличается <em>простотой</em> конструкции.</p>';

    expect(extractor()->toText($html))->toBe('Aurora CYCLON-100 отличается простотой конструкции.');
});

it('выбрасывает блоки редактора вместе с их JSON', function (): void {
    // В extra_description это почти всё содержимое: ссылки на инструкции,
    // галереи, баннеры. Их конфигурация не должна попасть в модель.
    $html = '<p>Полезный текст.</p>'
        .'<div data-type="customBlock" data-config=\'{"link_text":"Скачать инструкцию","url":"http://x/y.pdf"}\'></div>';

    expect(extractor()->toText($html))->toBe('Полезный текст.')
        ->and(extractor()->toText($html))->not->toContain('link_text');
});

it('выбрасывает картинки вместе с alt — в каталоге там имя файла', function (): void {
    $html = '<p>Компрессор.</p><img src="/x.jpg" alt="IMG_20240101_1.jpg">';

    expect(extractor()->toText($html))->toBe('Компрессор.');
});

it('оставляет подпись ссылки, но не адрес', function (): void {
    // Адрес товара бот берёт из карточки; адрес из описания только
    // сбивает его с толку и раздувает ввод.
    $html = '<p>Смотрите <a href="https://example.com/manual.pdf">инструкцию</a> к товару.</p>';

    expect(extractor()->toText($html))->toBe('Смотрите инструкцию к товару.')
        ->and(extractor()->toText($html))->not->toContain('example.com');
});

it('не спотыкается о кириллицу без объявленной кодировки', function (): void {
    // DOMDocument без явной обёртки с charset читает кириллицу как latin1
    // и портит текст молча — самая дорогая из возможных здесь ошибок.
    expect(extractor()->toText('<p>Мощность двигателя 2,2 кВт</p>'))
        ->toBe('Мощность двигателя 2,2 кВт');
});

it('разворачивает html-сущности и неразрывные пробелы', function (): void {
    expect(extractor()->toText('<p>Давление&nbsp;10&nbsp;атм &laquo;Циклон&raquo;</p>'))
        ->toBe('Давление 10 атм «Циклон»');
});

it('режет по границе слова и ставит многоточие', function (): void {
    // Потолок обязателен: самое длинное описание в каталоге — 20 695 знаков.
    $text = extractor()->toText('<p>'.str_repeat('слово ', 100).'</p>', 50);

    expect(mb_strlen($text))->toBeLessThanOrEqual(51)
        ->and($text)->toEndWith('…')
        ->and($text)->not->toContain('сло…');
});

it('режет с конца — таблица характеристик стоит в начале', function (): void {
    $html = '<table><tr><td>Мощность</td><td>2,2 кВт</td></tr></table><p>'.str_repeat('текст ', 200).'</p>';

    expect(extractor()->toText($html, 60))->toStartWith('Мощность: 2,2 кВт');
});

it('молчит на пустом описании', function (): void {
    expect(extractor()->toText(null))->toBe('')
        ->and(extractor()->toText('   '))->toBe('')
        ->and(extractor()->toText('<p></p><div></div>'))->toBe('');
});

it('не повторяет одну и ту же строку подряд', function (): void {
    // Обычное дело после выброшенных картинок и вложенных div.
    $html = '<div><p>Комплект поставки:</p></div><div>Комплект поставки:</div>';

    expect(extractor()->toText($html))->toBe('Комплект поставки:');
});
