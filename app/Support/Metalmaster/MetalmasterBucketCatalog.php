<?php

namespace App\Support\Metalmaster;

use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierProfile;

class MetalmasterBucketCatalog
{
    public function __construct(
        private readonly MetalmasterSupplierProfile $profile,
    ) {}

    public function sourceFile(): string
    {
        return $this->profile->defaultBucketsFile();
    }

    /**
     * @return array<string, string>
     */
    public function options(?string $search = null, int $limit = 100): array
    {
        $needle = mb_strtolower(trim((string) $search));
        $options = [];

        foreach ($this->rows() as $key => $row) {
            $label = $this->labelForRow($row);
            $sourceUrl = mb_strtolower((string) ($row['source_url'] ?? ''));

            if (
                $needle !== ''
                && ! str_contains(mb_strtolower($key), $needle)
                && ! str_contains(mb_strtolower($label), $needle)
                && ! str_contains($sourceUrl, $needle)
            ) {
                continue;
            }

            $options[$key] = $label;

            if (count($options) >= $limit) {
                break;
            }
        }

        return $options;
    }

    public function label(?string $bucket): ?string
    {
        $normalizedBucket = trim((string) $bucket);

        if ($normalizedBucket === '') {
            return null;
        }

        $row = $this->rows()[$normalizedBucket] ?? null;

        if (! is_array($row)) {
            return $normalizedBucket;
        }

        return $this->labelForRow($row);
    }

    public function hasBucket(?string $bucket): bool
    {
        $normalizedBucket = trim((string) $bucket);

        return $normalizedBucket !== '' && array_key_exists($normalizedBucket, $this->rows());
    }

    /**
     * @return array<string, array{key: string, name: string, items_count: int, source_url: string}>
     */
    private function rows(): array
    {
        $raw = @file_get_contents($this->sourceFile());

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            $rows = array_values(array_filter($decoded, 'is_array'));
        } else {
            $rows = $decoded['buckets'] ?? [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }

        $bucketRows = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row['bucket'] ?? ''));

            if ($key === '') {
                continue;
            }

            $bucketRows[$key] = [
                'key' => $key,
                'name' => $key,
                'items_count' => max(0, (int) ($row['products_count'] ?? 0)),
                'source_url' => trim((string) ($row['category_url'] ?? '')),
            ];
        }

        uasort($bucketRows, function (array $left, array $right): int {
            $countDiff = (int) ($right['items_count'] ?? 0) <=> (int) ($left['items_count'] ?? 0);

            if ($countDiff !== 0) {
                return $countDiff;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $bucketRows;
    }

    /**
     * @param  array{key: string, name: string, items_count: int, source_url: string}  $row
     */
    private function labelForRow(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        $key = trim((string) ($row['key'] ?? ''));
        $itemsCount = max(0, (int) ($row['items_count'] ?? 0));

        $label = $name !== '' ? $name : $key;

        if ($itemsCount > 0) {
            $label .= ' ('.$itemsCount.')';
        }

        return $label;
    }
}
