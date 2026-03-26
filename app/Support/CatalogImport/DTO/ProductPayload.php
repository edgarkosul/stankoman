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
        public ?string $description = null,
        public ?string $brand = null,
        public ?int $priceAmount = null,
        public ?string $currency = null,
        public ?bool $inStock = null,
        public ?int $qty = null,
        public array $images = [],
        public array $attributes = [],
        public array $source = [],
        public ?string $title = null,
        public ?string $sku = null,
        public ?string $country = null,
        public ?int $discountPrice = null,
        public ?string $short = null,
        public ?string $extraDescription = null,
        public ?string $instructions = null,
        public ?string $video = null,
        public ?string $promoInfo = null,
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
    ) {}
}
