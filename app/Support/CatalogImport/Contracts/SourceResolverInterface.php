<?php

namespace App\Support\CatalogImport\Contracts;

use App\Support\CatalogImport\DTO\ResolvedSource;

interface SourceResolverInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function resolve(string $source, array $options = []): ResolvedSource;
}
