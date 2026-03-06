<?php

namespace App\Support\CatalogImport\Yml;

final readonly class YmlStream
{
    /**
     * @param  array<int, string>  $categories
     * @param  array<int, int|null>  $categoryParents
     * @param  \Generator<int, YmlOfferRecord>  $offers
     */
    public function __construct(
        public array $categories,
        public array $categoryParents,
        public \Generator $offers,
    ) {}
}
