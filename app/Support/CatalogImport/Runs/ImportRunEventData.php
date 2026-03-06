<?php

namespace App\Support\CatalogImport\Runs;

use Carbon\CarbonInterface;

final readonly class ImportRunEventData
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $runId,
        public ?string $supplier,
        public string $stage,
        public string $result,
        public ?string $sourceRef = null,
        public ?string $externalId = null,
        public ?int $productId = null,
        public ?int $sourceCategoryId = null,
        public ?int $rowIndex = null,
        public ?string $code = null,
        public ?string $message = null,
        public array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(?CarbonInterface $timestamp = null): array
    {
        $timestamp ??= now();

        return [
            'run_id' => $this->runId,
            'supplier' => $this->supplier,
            'stage' => $this->stage,
            'result' => $this->result,
            'source_ref' => $this->sourceRef,
            'external_id' => $this->externalId,
            'product_id' => $this->productId,
            'source_category_id' => $this->sourceCategoryId,
            'row_index' => $this->rowIndex,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->encodeContext(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function encodeContext(): ?string
    {
        if ($this->context === []) {
            return null;
        }

        $encoded = json_encode($this->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
