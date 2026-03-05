<?php

namespace App\Support\CatalogImport\Yml;

final readonly class YmlStream
{
    /**
     * @param  array<int, string>  $categories
     * @param  \Generator<int, YmlOfferRecord>  $offers
     */
    public function __construct(
        public array $categories,
        public \Generator $offers,
    ) {}
}
