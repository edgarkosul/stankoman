<?php

use App\Services\Ai\Support\ProductLinkGuard;

/*
 * Названный товар без ссылки. Четвёртая попытка донора добиться одного и того
 * же: правило в промпте, приписка в выдаче, имя готовой ссылкой — и всё равно
 * замер 04.09.2026 вернул ответ с пятью товарами и без единого адреса.
 * Товары в примерах — наши, из живого каталога.
 */

$guard = fn (): ProductLinkGuard => new ProductLinkGuard;

it('дописывает ссылку на товар, названный без неё', function () use ($guard): void {
    $text = 'Из того, что в наличии: **компрессор Hansmann RSE 7.5-8** — **24 мес.** гарантии.';

    expect($guard()->ensure($text, [
        ['name' => 'Винтовой компрессор Hansmann RSE 7.5-8 с частотным преобразователем',
            'url' => 'https://intertooler.ru/product/vintovoi-kompressor-hansmann-rse-7-5-8'],
    ]))->toBe(
        'Из того, что в наличии: **компрессор Hansmann RSE 7.5-8** — **24 мес.** гарантии.'
        ."\n\n".'- [Винтовой компрессор Hansmann RSE 7.5-8 с частотным преобразователем]'
        .'(https://intertooler.ru/product/vintovoi-kompressor-hansmann-rse-7-5-8)'
    );
});

it('молчит, если ссылка уже стоит в тексте', function () use ($guard): void {
    $text = 'Смотрите [станок BSM-115](https://intertooler.ru/product/bs-115) — 12 мес.';

    expect($guard()->ensure($text, [
        ['name' => 'Ленточнопильный станок METAL MASTER BSM-115', 'url' => 'https://intertooler.ru/product/bs-115'],
    ]))->toBe($text);
});

it('молчит о товаре, которого в ответе нет', function () use ($guard): void {
    // Поиск показал пять карточек, бот назвал одну. Ссылки на четыре
    // остальные были бы не помощью, а мусором.
    $text = 'Подходит **станок BSM-115** — 12 мес. гарантии.';

    expect($guard()->ensure($text, [
        ['name' => 'Ленточнопильный станок METAL MASTER BSM-115', 'url' => 'https://intertooler.ru/p/1'],
        ['name' => 'Ленточнопильный станок Metal Master BSM-400 MFR', 'url' => 'https://intertooler.ru/p/2'],
    ]))->toContain('/p/1')->not->toContain('/p/2');
});

it('узнаёт обозначение из двух слов', function () use ($guard): void {
    // «RSE 7.5-8»: по отдельности половинки не годятся — «7.5-8» найдётся
    // в любом тексте с дробью, «RSE» опознаёт всю линейку.
    $text = 'Компрессор Hansmann RSE 7.5-8 есть в наличии за 108 596 руб.';

    expect($guard()->ensure($text, [
        ['name' => 'Винтовой компрессор Hansmann RSE 7.5-8 с частотным преобразователем',
            'url' => 'https://intertooler.ru/p/rse'],
    ]))->toContain('- [Винтовой компрессор Hansmann RSE 7.5-8');
});

it('не трогает товар без обозначения в имени', function () use ($guard): void {
    // «Компрессор винтовой» опознавался бы по слову «компрессор», то есть
    // по сотням товаров. Лучше не дописать ссылку, чем дописать чужую.
    $text = 'Вам подойдёт компрессор винтовой.';

    expect($guard()->ensure($text, [
        ['name' => 'Компрессор винтовой', 'url' => 'https://intertooler.ru/p/3'],
    ]))->toBe($text);
});

it('не путает соседние модели серии', function () use ($guard): void {
    // Разница в одну цифру — другая машина, другие деньги. В каталоге рядом
    // стоят RSE 7.5-8 и RSE 7.5-10, и путать их нельзя.
    $text = 'Есть Hansmann RSE 7.5-10 за 108 596 руб.';

    expect($guard()->ensure($text, [
        ['name' => 'Винтовой компрессор Hansmann RSE 7.5-8', 'url' => 'https://intertooler.ru/p/758'],
    ]))->toBe($text);
});

it('переживает пустой ответ и пустой список', function () use ($guard): void {
    expect($guard()->ensure('', [['name' => 'Станок BSM-115', 'url' => 'https://x/1']]))->toBe('')
        ->and($guard()->ensure('Текст ответа.', []))->toBe('Текст ответа.');
});
