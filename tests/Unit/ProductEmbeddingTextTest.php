<?php

use App\Services\Ai\Support\ProductTextExtractor;
use App\Shop\ProductEmbeddingText;
use App\Support\Products\ProductSpecs;

/*
 * Текст товара, уходящий в вектор. Здесь проверяется то, что от базы
 * не зависит: отпечаток.
 *
 * Отпечаток — не деталь реализации, а механизм инкрементности: по нему
 * ночная команда решает, тратить ли вызов шлюза. Ошибка в одну сторону —
 * каталог переэмбеддивается каждую ночь за деньги, в другую — правки
 * описаний не доезжают до поиска никогда.
 */

function embeddingText(int $limit = 1200): ProductEmbeddingText
{
    return new ProductEmbeddingText(new ProductTextExtractor, new ProductSpecs, $limit);
}

it('даёт одинаковый отпечаток одинаковому тексту', function (): void {
    $builder = embeddingText();

    expect($builder->hash('Компрессор Hansmann, 425 л/мин'))
        ->toBe($builder->hash('Компрессор Hansmann, 425 л/мин'));
});

it('замечает любую правку текста', function (): void {
    $builder = embeddingText();

    // Разница в одну цифру — это другой товар для поиска по смыслу.
    expect($builder->hash('Производительность: 425 л/мин'))
        ->not->toBe($builder->hash('Производительность: 435 л/мин'));
});

it('отпечаток не зависит от длины — это hex sha256', function (): void {
    expect(embeddingText()->hash(str_repeat('текст ', 1000)))->toHaveLength(64)
        ->and(embeddingText()->hash(''))->toHaveLength(64);
});
