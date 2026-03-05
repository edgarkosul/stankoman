<?php

namespace App\Support\CatalogImport\Contracts;

use App\Support\CatalogImport\DTO\ResolvedSource;

interface RecordParserInterface
{
    /**
     * @param  array<string, mixed>  $options
     * @return \Generator<int, mixed>
     */
    public function parse(ResolvedSource $source, array $options = []): \Generator;
}
