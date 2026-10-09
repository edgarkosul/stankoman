<?php

namespace App\Services\Ai\Data;

/**
 * Подбор по характеристикам: ВСЕ товары разделов, а не первые по словам.
 *
 * Разделы обязательны — без них «просмотреть всё» значило бы четыре тысячи
 * товаров каталога, где «производительность» есть и у компрессора,
 * и у поломоечной машины.
 */
final readonly class SpecQuery
{
    /**
     * @param  list<SpecCondition>  $conditions  товар подходит, если прошёл все
     * @param  list<int>  $sectionIds
     */
    public function __construct(
        public array $conditions,
        public array $sectionIds = [],
        public ?int $categoryId = null,
        public bool $inStockOnly = false,
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public bool $seesDiscounts = false,
        public int $limit = 10,
    ) {}
}
