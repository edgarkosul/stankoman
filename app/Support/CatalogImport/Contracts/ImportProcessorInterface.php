<?php

namespace App\Support\CatalogImport\Contracts;

use App\Support\CatalogImport\DTO\ImportProcessResult;
use App\Support\CatalogImport\DTO\ProductPayload;

interface ImportProcessorInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function process(ProductPayload $payload, array $options = []): ImportProcessResult;
}
