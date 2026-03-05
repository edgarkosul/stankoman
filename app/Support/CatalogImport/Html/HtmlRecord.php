<?php

namespace App\Support\CatalogImport\Html;

final readonly class HtmlRecord
{
    /**
     * @param  array<string, string|null>  $fields
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $index,
        public string $html,
        public array $fields = [],
        public array $meta = [],
    ) {}
}
