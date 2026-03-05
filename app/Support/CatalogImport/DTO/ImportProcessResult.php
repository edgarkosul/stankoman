<?php

namespace App\Support\CatalogImport\DTO;

final readonly class ImportProcessResult
{
    /**
     * @param  array<int, ImportError>  $errors
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $operation,
        public array $errors = [],
        public array $meta = [],
    ) {}

    public function isSuccess(): bool
    {
        foreach ($this->errors as $error) {
            if ($error->isFatal()) {
                return false;
            }
        }

        return true;
    }
}
