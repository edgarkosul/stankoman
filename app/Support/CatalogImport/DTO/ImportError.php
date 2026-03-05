<?php

namespace App\Support\CatalogImport\DTO;

use App\Support\CatalogImport\Enums\ImportErrorLevel;

final readonly class ImportError
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $code,
        public string $message,
        public ImportErrorLevel $level = ImportErrorLevel::Record,
        public ?int $rowIndex = null,
        public array $context = [],
    ) {}

    public function isFatal(): bool
    {
        return $this->level === ImportErrorLevel::Fatal;
    }
}
