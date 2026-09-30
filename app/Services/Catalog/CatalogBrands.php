<?php

namespace App\Services\Catalog;

use App\Services\Ai\Contracts\ProductLookup;
use App\Support\Search\BrandSpelling;
use App\Support\Search\LatinQuery;

/**
 * Назван ли в запросе бренд из каталога.
 *
 * Существует ради одного решения — применять ли к поиску фильтр по типу
 * техники. Бренд сужает выдачу сильнее, чем тип, и тип к нему может только
 * потерять нужное: слово «компрессор» подходит к нескольким разделам,
 * в фильтр идут четыре, и раздела с нужным брендом среди них может
 * не оказаться. У kratonshop ровно это случилось 07.09.2026 на «Компрессор
 * хансман»: с фильтром поиск отдавал Remeza, Denzel и два «Зубра», и бот
 * честно отвечал, что бренда в каталоге нет. Его 237 товаров.
 *
 * Список брендов приходит через ProductLookup, а не запросом к Product:
 * донорский класс читал модель сам и этим нарушал правило шва. Здесь
 * осталась чистая половина — сопоставление, где и лежит вся тонкость.
 *
 * ПОРОГ В ЧЕТЫРЕ ЗНАКА. У нас короче семь брендов: JIB, KEN, KMT, LTT, TOR,
 * TSS, ПТК. Порог их теряет, и это дешевле, чем ловить слова из вопросов:
 * у донора из-за «КВТ» каждый второй вопрос о мощности считался брендовым.
 *
 * ДОПУСК НА ОПЕЧАТКУ взят у Meilisearch: одна на слово от пяти знаков, две
 * от девяти — те же minWordSizeForTypos, что стоят в индексе (в нашем
 * config/scout.php typoTolerance не переопределён). Иначе поиск бренд
 * прощал бы, а справочник — нет. Именно этот допуск связывает «харсман»
 * с «hansmann».
 *
 * КАЛИБРОВКА НА СВОЁМ СПИСКЕ (15.09.2026, 60 брендов дева, 140 обычных
 * вопросов). Донорские правила дали здесь 15 ложных срабатываний, и почти
 * все — на словах, без которых магазин станков не разговаривает: «сталь»,
 * «стальной», «стали» → Stalex; «металла» → MetalMaster; «сверла» →
 * Everlast; «зерно» → Zero. Цена не косметическая: на «станок для резки
 * металла» с типом «ленточнопильный» фильтр по типу слетал бы именно там,
 * где он нужнее всего. Причины две, и лечатся они по-разному — см.
 * MIN_PREFIX и `$lookalikes`.
 */
final class CatalogBrands
{
    /** Короче — не бренд, а слово из вопроса. См. шапку. */
    private const MIN_LENGTH = 4;

    /**
     * С какой длины начало слова доводит до бренда.
     *
     * У донора хватало четырёх знаков, у нас нет: мягкий знак транслитератор
     * отдаёт апострофом, разделитель режет по нему, и «сталь» приходит сюда
     * как «stal» — начало Stalex. Пять знаков оставляют донорский случай
     * «хансм» → Hansmann и убирают все четырёхбуквенные огрызки.
     */
    private const MIN_PREFIX = 5;

    /**
     * @param  list<string>  $lookalikes  основы русских слов, похожих на бренды
     *                                    каталога, — из config/ai_support.php
     */
    public function __construct(
        private readonly ProductLookup $products,
        private readonly array $lookalikes = [],
    ) {}

    /** Бренд, названный в запросе, или null. */
    public function mentionedIn(string $query): ?string
    {
        return self::match($query, $this->products->brands(), $this->lookalikes);
    }

    /**
     * Чистая половина: сопоставление запроса со списком.
     *
     * @param  list<string>  $brands
     * @param  list<string>  $lookalikes  основы слов, которые брендом не бывают
     */
    public static function match(string $query, array $brands, array $lookalikes = []): ?string
    {
        $spoken = self::bySound($query, $brands);

        if ($spoken !== null) {
            return $spoken;
        }

        $haystack = self::words(self::withoutLookalikes($query, $lookalikes));

        if ($haystack === '') {
            return null;
        }

        $tokens = explode(' ', $haystack);

        /*
         * Совпадение по опечатке придерживаем до конца списка: точное
         * написание должно побеждать, в каком бы порядке ни лежали бренды.
         * Иначе у донора «генератор Huter» отдавал CUTERAL — «huter» отстоит
         * от «cuter» на одну замену, а CUTERAL в списке стоит раньше.
         */
        $fuzzy = null;

        foreach ($brands as $brand) {
            $needle = self::words($brand);

            if ($needle === '' || mb_strlen($needle) < self::MIN_LENGTH) {
                continue;
            }

            // Бренд из нескольких слов («Энкор Корвет», «WHITE SIBERIA»)
            // ищем целиком: по отдельности его части ничего не значат.
            if (str_contains($needle, ' ')) {
                if (str_contains(' '.$haystack.' ', ' '.$needle.' ')) {
                    return $brand;
                }

                continue;
            }

            foreach ($tokens as $token) {
                if (strlen($token) < self::MIN_LENGTH) {
                    continue;
                }

                if ($token === $needle) {
                    return $brand;
                }

                // Начало слова — так же, как ищет сам Meilisearch: «хансм»
                // должен доводить до Hansmann, а не только полное написание.
                if (strlen($token) >= self::MIN_PREFIX && str_starts_with($needle, $token)) {
                    return $brand;
                }

                $comparable = self::comparable($needle, $token);

                /*
                 * Порог начала слова действует и здесь, а не только на точном
                 * совпадении с префиксом. Иначе он не работает вовсе: сравнение
                 * с ОБРЕЗАННЫМ брендом — это тоже сравнение по началу, и при
                 * нулевом допуске (слово короче пяти знаков) «ханс» проходило
                 * как совпадение с «hans» от «hansmann», а «стал» — с «stal»
                 * от «stalex». Поймано тестом 15.09.2026, уже после того как
                 * порог казался поставленным.
                 */
                if ($comparable !== $needle && strlen($token) < self::MIN_PREFIX) {
                    continue;
                }

                if ($fuzzy === null && levenshtein($token, $comparable) <= self::tolerance($token)) {
                    $fuzzy = $brand;
                }
            }
        }

        return $fuzzy;
    }

    /**
     * Запрос без слов-двойников.
     *
     * Вторая причина ложных срабатываний — окончания. «Стали» отстоит от
     * начала «stalex» на одну замену, «металла» от начала «metalmaster» —
     * тоже на одну, и допуск на опечатку честно их прощает. Правилом это
     * не отделить: «харсман» от «hansmann» отстоит ровно так же, и его
     * прощать надо. Отделяет знание языка, а его у нас нет — поэтому
     * двойники перечислены в конфиге основами и выбрасываются из запроса
     * до сравнения, по исходному написанию, до транслитерации.
     *
     * Бренд, записанный кириллицей, от этого не страдает: так пишут
     * «хансман» и «ремеза», но не «стали» вместо Stalex.
     *
     * @param  list<string>  $lookalikes
     */
    private static function withoutLookalikes(string $query, array $lookalikes): string
    {
        if ($lookalikes === []) {
            return $query;
        }

        $kept = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach ($lookalikes as $stem) {
                $stem = mb_strtolower(trim($stem));

                if ($stem !== '' && str_starts_with($word, $stem)) {
                    continue 2;
                }
            }

            $kept[] = $word;
        }

        return implode(' ', $kept);
    }

    /**
     * Бренд, записанный кириллицей «как слышится»: «сталекс», «кроссэйр», «вактул».
     *
     * Правило то же, что у поиска (BrandSpelling::fold), и обязано быть тем же.
     * Поиск находит Stalex по «сталекс» (fix(search) 15.09.2026), и справочник,
     * не узнавший здесь бренд, повесил бы на такой запрос фильтр по типу
     * техники — отняв у выдачи ровно то, что поиск нашёл.
     *
     * Совпадение только точное. У свёртки на 1 593 кириллических словах
     * каталога ноль ложных совпадений, а допуск на опечатку поверх неё вернул
     * бы «стали» → Stalex. Проверка идёт раньше списка двойников: «сталекс»
     * начинается с «стал» и иначе был бы выброшен до сравнения.
     *
     * Бренды из нескольких слов и свёртки, общие для двух брендов, пропускаются —
     * так же, как у поиска.
     *
     * @param  list<string>  $brands
     */
    private static function bySound(string $query, array $brands): ?string
    {
        $known = [];
        $ambiguous = [];

        foreach ($brands as $brand) {
            $needle = self::words($brand);

            if ($needle === '' || str_contains($needle, ' ')) {
                continue;
            }

            $key = BrandSpelling::fold($needle);

            if (strlen($key) < self::MIN_LENGTH) {
                continue;
            }

            if (isset($known[$key]) && $known[$key] !== $brand) {
                $ambiguous[$key] = true;
            }

            $known[$key] ??= $brand;
        }

        $known = array_diff_key($known, $ambiguous);

        foreach (explode(' ', self::words($query)) as $token) {
            if ($token === '') {
                continue;
            }

            $key = BrandSpelling::fold($token);

            if (isset($known[$key])) {
                return $known[$key];
            }
        }

        return null;
    }

    /**
     * Латиница, нижний регистр, вместо любых разделителей — один пробел.
     *
     * Одна и та же нормализация для запроса и для бренда: сравнивать их
     * можно только приведёнными к общему виду, а латиница здесь потому же,
     * почему и в поиске, — покупатель пишет бренд кириллицей (LatinQuery).
     * Хвостовой пробел («Термит ») и дефис («Tech-Nick») она переживает.
     */
    private static function words(string $text): string
    {
        $latin = LatinQuery::toLatin($text);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $latin));
    }

    /**
     * Та часть бренда, с которой честно сравнивать слово из запроса.
     *
     * Meilisearch считает опечатки не по всему слову из индекса, а по его
     * НАЧАЛУ длиной в запрос: последнее слово запроса он ищет как префикс.
     * Поэтому «harsman» у него отстоит от «hansmann» на одну замену (против
     * «hansman»), а не на две — и находит. Сравнение целиком обещало бы
     * «тот же допуск, что у индекса», а давало более строгий (kratonshop,
     * 09.09.2026: «Хансман» справочник узнавал, «Харсман» — уже нет).
     */
    private static function comparable(string $brand, string $token): string
    {
        return strlen($brand) > strlen($token) ? substr($brand, 0, strlen($token)) : $brand;
    }

    /** Допуск на опечатку — тот же, что у индекса. */
    private static function tolerance(string $token): int
    {
        return match (true) {
            strlen($token) >= 9 => 2,
            strlen($token) >= 5 => 1,
            default => 0,
        };
    }
}
