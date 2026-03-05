<?php

namespace App\Support\CatalogImport\DTO;

final readonly class AdapterIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public string $severity = 'error',
    ) {}
}
