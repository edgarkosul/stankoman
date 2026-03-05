<?php

namespace App\Support\CatalogImport\Xml;

final readonly class XmlRecord
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public int $index,
        public string $nodeName,
        public array $attributes,
        public string $xml,
    ) {}
}
