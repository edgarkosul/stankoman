<?php

namespace App\Support\Search;

/**
 * Бренд, записанный кириллицей «как слышится»: «сталекс» → Stalex.
 *
 * LatinQuery переводит запрос в латиницу буква в букву, и для большинства
 * брендов этого хватает («хансман» → hansman, опечатку Meilisearch простит).
 * Но у части брендов прочтение расходится с написанием сильнее, чем на одну
 * опечатку: сталекс → staleks против Stalex (x↔ks), вактул → vaktul против
 * Vactool (c↔k, oo↔u), кроссэйр → krossejr против CrossAir (ai↔ej),
 * метмашин → metmasin против MetMachine (ch↔sh). По каталогу это CrossAir
 * (150 товаров), Stalex (122), EFCO (84), Vactool (67), MetMachine (57),
 * Spitzenreiter (52) — дев, 15.09.2026.
 *
 * ПОЧЕМУ НА СТОРОНЕ ЗАПРОСА. Алиасы в документе индекса потребовали бы
 * scout:sync-index-settings и полной пересборки на проде, а пересборка
 * начинается с removeAllFromSearch() — на всё время сборки поиск на сайте
 * пуст. Ради редкого запроса (в логах 05–15.09 бренды набирали латиницей)
 * такая цена несоразмерна, а здесь откат — revert.
 *
 * ПОЧЕМУ ТОЛЬКО ТОЧНОЕ СОВПАДЕНИЕ. В ветке ассистента справочник брендов
 * с допуском на опечатку узнавал Stalex в «стали», MetalMaster в «металла»,
 * Zero в «зерно». Ложное срабатывание ломает обычный поиск ради редкого,
 * поэтому здесь нет ни опечаток, ни начала слова: слово заменяется, только
 * если его свёртка РАВНА свёртке бренда. На 1593 кириллических словах из
 * названий товаров и разделов дева таких совпадений ноль; калибровка —
 * BrandSpellingTest.
 */
final class BrandSpelling
{
    /** Короче свёртка совпадает с обычными словами слишком легко. */
    private const MIN_KEY_LENGTH = 4;

    /**
     * Запрос для индекса: LatinQuery плюс написание бренда вместо его прочтения.
     *
     * Меняются только слова, набранные кириллицей: латиницу покупатель уже
     * написал так, как считает правильным, и артикул в ней трогать нельзя.
     *
     * @param  list<string>  $brands  бренды каталога, как они записаны в товарах
     */
    public static function substitute(string $query, array $brands): string
    {
        $latin = LatinQuery::normalize($query);

        if ($latin === '' || $brands === []) {
            return $latin;
        }

        $typed = explode(' ', trim((string) preg_replace('/\s+/u', ' ', $query)));
        $words = explode(' ', $latin);

        // Транслитерация пробелов не добавляет, но если слова всё же
        // разошлись, сопоставлять их по номеру нельзя — лучше без подстановки.
        if (count($typed) !== count($words)) {
            return $latin;
        }

        $spellings = self::spellings($brands);

        foreach ($words as $i => $word) {
            if (preg_match('/\p{Cyrillic}/u', $typed[$i]) !== 1) {
                continue;
            }

            $spelling = $spellings[self::fold($word)] ?? null;

            // «ведком», «старт» уже написаны как бренд — заменять нечего,
            // а замена съела бы знаки вокруг слова.
            if ($spelling !== null && $spelling !== preg_replace('/[^a-z0-9]/', '', $word)) {
                $words[$i] = $spelling;
            }
        }

        return implode(' ', $words);
    }

    /**
     * Фонетическая свёртка латиницы: одно звучание — одна запись.
     *
     * Правила взяты ровно под расхождения, найденные в каталоге, и не шире:
     * каждое новое правило — это новые совпадения с обычными словами.
     */
    public static function fold(string $latin): string
    {
        $key = (string) preg_replace('/[^a-z]/', '', strtolower($latin));

        // Сочетания раньше одиночных букв: strtr берёт самый длинный ключ.
        $key = strtr($key, [
            'sch' => 's', 'ch' => 's', 'sh' => 's',   // MetMachine ↔ метмашин
            'tz' => 'c', 'ts' => 'c', 'tc' => 'c',    // Spitzenreiter ↔ спитценрайтер
            'ph' => 'f', 'ck' => 'k', 'qu' => 'kv', 'w' => 'v',
            'x' => 'ks',                               // Stalex ↔ сталекс
        ]);
        $key = str_replace('c', 'k', $key);            // EFCO ↔ эфко, Vactool ↔ вактул
        $key = strtr($key, [
            'oo' => 'u',                               // Vactool ↔ вактул
            'ai' => 'ej', 'ei' => 'ej', 'aj' => 'ej', 'ay' => 'ej', 'ey' => 'ej', // CrossAir ↔ кроссэйр
        ]);
        $key = (string) preg_replace('/(.)\1+/', '$1', $key);

        // Немая конечная e: MetMachine читается «метмашин».
        return (string) preg_replace('/(?<=[^aeiouy])e$/', '', $key);
    }

    /**
     * Свёртка → написание бренда для подстановки в запрос.
     *
     * Бренды из нескольких слов и с дефисом («WHITE SIBERIA», «Tech-Nick»)
     * пропускаем: их части по отдельности совпадают с обычными словами,
     * а «техник» не должен становиться брендом. Свёртку, общую для двух
     * разных брендов, тоже: угадывать между ними нечем.
     *
     * @param  list<string>  $brands
     * @return array<string, string>
     */
    private static function spellings(array $brands): array
    {
        $spellings = [];
        $ambiguous = [];

        foreach ($brands as $brand) {
            $spelling = LatinQuery::toLatin($brand);

            if (preg_match('/^[a-z0-9]+$/', $spelling) !== 1) {
                continue;
            }

            $key = self::fold($spelling);

            if (strlen($key) < self::MIN_KEY_LENGTH) {
                continue;
            }

            if (isset($spellings[$key]) && $spellings[$key] !== $spelling) {
                $ambiguous[$key] = true;
            }

            $spellings[$key] = $spelling;
        }

        return array_diff_key($spellings, $ambiguous);
    }
}
