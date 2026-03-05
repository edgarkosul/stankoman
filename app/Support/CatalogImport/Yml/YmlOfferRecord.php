<?php

namespace App\Support\CatalogImport\Yml;

final readonly class YmlOfferRecord
{
    public function __construct(
        public string $id,
        public ?string $type,
        public ?bool $available,
        public string $xml,
    ) {}
}
