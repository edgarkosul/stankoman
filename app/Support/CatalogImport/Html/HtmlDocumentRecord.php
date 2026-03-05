<?php

namespace App\Support\CatalogImport\Html;

final readonly class HtmlDocumentRecord
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $url,
        public HtmlRecord $document,
        public array $meta = [],
    ) {}
}
