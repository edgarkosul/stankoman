<?php

namespace App\Support;

class ImageDerivativesGenerationResult
{
    /**
     * @param array<int> $generatedWidths
     * @param array<string, string> $skipped
     */
    public function __construct(
        public string $key,
        public string $sourcePath,
        public array $generatedWidths = [],
        public array $skipped = [],
        public string $status = 'fail',
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'sourcePath' => $this->sourcePath,
            'generatedWidths' => $this->generatedWidths,
            'skipped' => $this->skipped,
            'status' => $this->status,
        ];
    }
}
