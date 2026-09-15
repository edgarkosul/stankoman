<?php

use App\Services\Kb\KbArticleTitle;

// Заголовок статьи из реплики чата — чистая функция: ни базы, ни шлюза.

it('склеивает переносы и лишние пробелы в одну строку', function (): void {
    expect(KbArticleTitle::fromQuestion("можно ли вернуть\n\n  товар  через год"))
        ->toBe('Можно ли вернуть товар через год');
});

it('поднимает первую букву', function (): void {
    expect(KbArticleTitle::fromQuestion('гарантия на станок'))
        ->toBe('Гарантия на станок');
});

it('не трогает заголовок, начинающийся не с буквы', function (): void {
    expect(KbArticleTitle::fromQuestion('380 вольт нужно для этого станка'))
        ->toBe('380 вольт нужно для этого станка');
});

it('снимает хвостовую пунктуацию', function (): void {
    expect(KbArticleTitle::fromQuestion('а скидку дадите???'))->toBe('А скидку дадите')
        ->and(KbArticleTitle::fromQuestion('доставка до Крыма есть...'))->toBe('Доставка до Крыма есть');
});

it('оставляет вопросительный знак внутри фразы', function (): void {
    expect(KbArticleTitle::fromQuestion('гарантия есть? и сколько она длится'))
        ->toBe('Гарантия есть? и сколько она длится');
});

it('не режет закрывающую скобку и кавычку', function (): void {
    expect(KbArticleTitle::fromQuestion('нужна ленточная пила (по металлу)'))
        ->toBe('Нужна ленточная пила (по металлу)');
});

it('на пустой реплике отдаёт пустую строку', function (): void {
    expect(KbArticleTitle::fromQuestion("  \n "))->toBe('')
        ->and(KbArticleTitle::fromQuestion('?!'))->toBe('');
});

it('обрезает длинную реплику по границе слова', function (): void {
    $question = 'Вопрос про доставку '.str_repeat('вопрос про доставку ', 29);
    $title = KbArticleTitle::fromQuestion($question);
    $kept = mb_substr($title, 0, -1);

    expect(mb_strlen($title))->toBeLessThanOrEqual(255)
        ->and($title)->toEndWith('…')
        // Обрубков слов в заголовке быть не должно: сохранённая часть —
        // начало реплики, и кончается она ровно там, где был пробел.
        ->and(str_starts_with($question, $kept))->toBeTrue()
        ->and(mb_substr($question, mb_strlen($kept), 1))->toBe(' ');
});

it('режет по букве, если слово одно и оно длиннее поля', function (): void {
    $title = KbArticleTitle::fromQuestion(str_repeat('щ', 400));

    expect(mb_strlen($title))->toBe(255)
        ->and($title)->toEndWith('…');
});

it('считает длину в буквах, а не в байтах', function (): void {
    // 250 кириллических букв — это 500 байт: обрезка по байтам и укоротила бы
    // заголовок вдвое, и оставила бы битый символ на конце.
    expect(KbArticleTitle::fromQuestion(str_repeat('а', 250)))
        ->toBe(mb_strtoupper('а').str_repeat('а', 249));
});
