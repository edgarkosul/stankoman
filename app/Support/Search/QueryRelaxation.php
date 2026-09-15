<?php

namespace App\Support\Search;

/**
 * Какие слова выбросить из запроса, который ничего не нашёл.
 *
 * Meilisearch по умолчанию ищет с `matchingStrategy: last`: не хватает
 * результатов — выбрасывает слова С КОНЦА. Слово, которого нет ни в одном
 * товаре, но стоит первым, не выбрасывается никогда: «бензогенератор tehnotek»
 * давал 0, а «tehnotek бензогенератор» — 78 (замер 15.09.2026).
 *
 * Режим `frequency` для всех запросов проверен и отвергнут: пустых из 98 живых
 * запросов стало 20 вместо 30, но из 66 работающих у 26 выдача сузилась, у 15
 * сменилась первая тройка, два упали в ноль, а «электроскутер gt tank» начал
 * отдавать поломоечные машины. Поэтому работающий запрос не трогаем вовсе,
 * а пустой повторяем без слов, по которым индекс не нашёл ничего.
 *
 * Условие повтора осторожное, и цифры за ним такие: без условия из 25 пустых
 * запросов с прода 7 отдавали мусор («баллон гбо 35л» → гидронасос на 0,35 л,
 * «натяжитель полотна для лобзикового станка» → 320 станков «для»), с ним — 2.
 * Цена — один потерянный длинный запрос из тринадцати слов.
 */
final class QueryRelaxation
{
    /**
     * Сколько слов проверять по одному. Длиннее — это уже фраза, а не запрос:
     * повтор по её обрывкам находит «с сиденьем» у садового трактора.
     */
    public const MAX_WORDS = 8;

    /** Хоть одно оставшееся слово должно что-то значить, а не быть «02 pro» или «для». */
    private const MIN_LETTERS = 4;

    /**
     * @param  list<string>  $words
     */
    public static function worthProbing(array $words): bool
    {
        return count($words) >= 2 && count($words) <= self::MAX_WORDS;
    }

    /**
     * Номера слов, по которым в индексе нет ни одного товара.
     *
     * @param  list<int>  $hits  число попаданий по каждому слову отдельно
     * @return list<int>
     */
    public static function unmatched(array $hits): array
    {
        return array_keys(array_filter($hits, static fn (int $count): bool => $count === 0));
    }

    /**
     * Слова для повтора или null, если повторять не стоит.
     *
     * Повтор — только когда осталась хотя бы половина слов и среди них есть
     * слово от четырёх букв. Иначе запрос держится на обрывке («35л», «u2»,
     * «для станка»), и выдача по нему — мусор, который хуже честной пустоты.
     *
     * @param  list<string>  $words
     * @param  list<int>  $unmatched
     * @return list<string>|null
     */
    public static function retryWords(array $words, array $unmatched): ?array
    {
        if ($unmatched === []) {
            return null;
        }

        $kept = array_values(array_diff_key($words, array_flip($unmatched)));

        if ($kept === [] || count($kept) < count($unmatched)) {
            return null;
        }

        foreach ($kept as $word) {
            if (preg_match_all('/\p{L}/u', $word) >= self::MIN_LETTERS) {
                return $kept;
            }
        }

        return null;
    }
}
