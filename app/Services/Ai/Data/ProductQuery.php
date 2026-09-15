<?php

namespace App\Services\Ai\Data;

/**
 * Поиск товара так, как его понял инструмент ассистента.
 *
 * `text` — запрос словами покупателя, без перевода в латиницу: перевод
 * делает тот, кто ищет по словам (LatinQuery), а смысловому поиску
 * фазы 8 нужен оригинал.
 */
final readonly class ProductQuery
{
    /**
     * @param  list<int>  $sectionIds  разделы, найденные по типу техники; товар
     *                                 подходит, если лежит в любом из них
     */
    public function __construct(
        public string $text,
        public bool $seesDiscounts = false,
        public bool $inStockOnly = false,
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public ?int $categoryId = null,
        public array $sectionIds = [],
        public int $limit = 5,
    ) {}
}
