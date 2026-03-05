<?php

namespace App\Support\CatalogImport\DTO;

final readonly class AdapterResult
{
    /**
     * @param  array<int, AdapterIssue>  $issues
     */
    public function __construct(
        public ?ProductPayload $payload,
        public array $issues = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->payload !== null;
    }
}
