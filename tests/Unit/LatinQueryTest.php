<?php

use App\Support\Search\LatinQuery;

// Кросс-скриптовый поиск: покупатель пишет бренд кириллицей, каталог — латиницей.

it('переводит кириллический запрос в латиницу', function (): void {
    // Ровно тот запрос, на котором 07.09.2026 бот ответил «не нашлось»,
    // пока витрина находила семь компрессоров.
    expect(LatinQuery::normalize('Компрессор хансман'))->toBe('kompressor hansman')
        ->and(LatinQuery::normalize('Хансман'))->toBe('hansman')
        ->and(LatinQuery::normalize('ВедКом'))->toBe('vedkom');
});

it('латиницу оставляет как есть', function (): void {
    // Прогон через транслитератор здесь только испортил бы артикул:
    // регистр и знаки в нём значат ровно то, что написано.
    expect(LatinQuery::normalize('Hansmann RS 5,5A-10'))->toBe('Hansmann RS 5,5A-10')
        ->and(LatinQuery::normalize('ВК-J 15/10'))->toBe('vk-j 15/10');
});

it('схлопывает пробелы и переживает пустой запрос', function (): void {
    expect(LatinQuery::normalize("  Компрессор   винтовой \n"))->toBe('kompressor vintovoj')
        ->and(LatinQuery::normalize('   '))->toBe('');
});
