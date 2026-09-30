<?php

namespace App\Services\Ai\Data;

/**
 * Выдача поиска товаров вместе с тем, как она найдена.
 *
 * «Как найдена» — не подробность для лога, а часть ответа. Поиск повторяет
 * пустой запрос без слов, которых нет в каталоге (App\Support\Search\ProductTextSearch),
 * и на «электропитбайк white siberia belluga» отдаёт электромотоциклы
 * White Siberia. Витрина пишет над такой выдачей «Не нашлось: «электропитбайк»».
 * Бот обязан сказать то же самое, а узнать это ему неоткуда, кроме этого объекта.
 */
final readonly class ProductMatches
{
    /**
     * @param  list<ProductCard>  $cards
     * @param  string  $searchedText  запрос в том виде, в каком он ушёл в индекс: латиницей,
     *                                с написанием бренда вместо прочтения, без выброшенных слов
     * @param  list<string>  $unmatched  слова покупателя, по которым в каталоге нет ни одного товара
     * @param  bool  $relaxed  выдача собрана повтором без слов из `$unmatched`
     * @param  bool  $semantic  выдача собрана гибридным поиском: слова плюс смысл.
     *                          Тогда `$unmatched` бывает непустым и БЕЗ повтора —
     *                          слов в каталоге нет, а товары нашлись по смыслу
     *                          запроса, и выдавать их за точное попадание нельзя
     */
    public function __construct(
        public array $cards,
        public string $searchedText,
        public array $unmatched = [],
        public bool $relaxed = false,
        public bool $semantic = false,
    ) {}
}
