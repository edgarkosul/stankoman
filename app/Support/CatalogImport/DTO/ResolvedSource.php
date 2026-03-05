<?php

namespace App\Support\CatalogImport\DTO;

final readonly class ResolvedSource
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $source,
        public string $resolvedPath,
        public ?string $cacheKey = null,
        public array $meta = [],
    ) {}
}
