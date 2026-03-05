<?php

namespace App\Support\CatalogImport\DTO;

final readonly class ProductPayload
{
    /**
     * @param  array<int, string>  $images
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $brand = null,
        public ?int $priceAmount = null,
        public ?string $currency = null,
        public ?bool $inStock = null,
        public ?int $qty = null,
        public array $images = [],
        public array $attributes = [],
        public array $source = [],
    ) {}
}
