<?php

namespace App\Support\CatalogImport\DTO;

final readonly class RecordMappingResult
{
    /**
     * @param  array<int, ImportError>  $errors
     */
    public function __construct(
        public ?ProductPayload $payload,
        public array $errors = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->payload !== null && ! $this->hasFatalError();
    }

    public function hasFatalError(): bool
    {
        foreach ($this->errors as $error) {
            if ($error->isFatal()) {
                return true;
            }
        }

        return false;
    }
}
