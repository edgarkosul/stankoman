<?php

namespace App\Support\CatalogImport\Contracts;

use App\Support\CatalogImport\DTO\RecordMappingResult;

interface SupplierAdapterInterface
{
    public function mapRecord(mixed $record): RecordMappingResult;
}
