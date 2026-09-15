<?php

namespace App\Support\Search;

use Illuminate\Support\Str;

/**
 * Кросс-скриптовый поиск: «хансман» должен находить Hansmann.
 *
 * В каталоге 52 бренда из 60 пишутся латиницей (дев, 15.09.2026), а покупатель
 * набирает их кириллицей — как слышит. Meilisearch сам этого не свяжет:
 * опечатки он прощает внутри одного алфавита, а «Хансман» и «Hansmann» для
 * него слова без единой общей буквы. Поэтому в индексе рядом с `name`
 * и `brand` лежат `name_latin` и `brand_latin`, а запрос с кириллицей
 * переводится в латиницу ПЕРЕД поиском — тогда совпадение находится в этих полях.
 *
 * Вынесено из приватного ProductSearchService::toLatin(), где правило было
 * заперто в витрине. Причина — случай kratonshop 07.09.2026: витрина так
 * делала, а инструмент ассистента, написанный отдельно, — нет. Поиск в шапке
 * находил семь компрессоров Hansmann по «хансман», а бот на тот же запрос
 * отвечал «не нашлось» и уводил на другие бренды. Одно правило, две
 * реализации, разошедшиеся молча. Здесь в этот класс ходят оба: витрина
 * (ProductSearchService) и ассистент (App\Shop).
 *
 * Индексная половина пары живёт в Product::toLatin(): она переводит НАЗВАНИЯ
 * при записи в индекс. Свести их в одну функцию нельзя без переиндексации
 * всего каталога, а выгоды никакой: на кириллице правила совпадают, индексная
 * лишь дополнительно снимает диакритику с латиницы.
 */
final class LatinQuery
{
    /**
     * Запрос в том виде, в каком его стоит отдавать поиску по словам.
     *
     * Латиницу не трогаем: она уже совпадает с `name` и `brand` напрямую,
     * а лишний прогон через транслитератор только сломал бы артикулы.
     */
    public static function normalize(string $query): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));

        if ($query === '' || ! preg_match('/\p{Cyrillic}/u', $query)) {
            return $query;
        }

        return self::toLatin($query);
    }

    public static function toLatin(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $latin = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            // Фолбэк без intl — беднее на диакритике, но лучше, чем ничего.
            $latin = Str::lower(Str::ascii($text));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $latin));
    }
}
