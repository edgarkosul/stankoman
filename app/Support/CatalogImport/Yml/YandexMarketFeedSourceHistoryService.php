<?php

namespace App\Support\CatalogImport\Yml;

use App\Models\ImportFeedSource;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class YandexMarketFeedSourceHistoryService
{
    public const SOURCE_TYPE_URL = 'url';

    public const SOURCE_TYPE_UPLOAD = 'upload';

    private const SUPPLIER = 'yandex_market_feed';

    private const STORAGE_DISK = 'local';

    private const TEMP_UPLOAD_DIRECTORY = 'catalog-import/yandex-feed-uploads';

    private const DEDUPED_UPLOAD_DIRECTORY = 'catalog-import/yandex-feed-sources';

    private const RETENTION_DAYS = 365;

    /**
     * @return array<string, string>
     */
    public function historyOptions(?string $search = null, int $limit = 100): array
    {
        $this->pruneExpired();

        $query = ImportFeedSource::query()
            ->where('supplier', self::SUPPLIER)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id');

        $needle = $search !== null ? trim($search) : '';

        if ($needle !== '') {
            $query->where(function ($builder) use ($needle): void {
                $builder
                    ->where('source_url', 'like', "%{$needle}%")
                    ->orWhere('original_filename', 'like', "%{$needle}%")
                    ->orWhere('stored_path', 'like', "%{$needle}%");
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (ImportFeedSource $source): array => [
                (string) $source->id => $this->formatLabel($source),
            ])
            ->all();
    }

    public function historyOptionLabel(mixed $value): ?string
    {
        $sourceId = $this->normalizeNullableInt($value);

        if ($sourceId === null) {
            return null;
        }

        $source = ImportFeedSource::query()
            ->where('supplier', self::SUPPLIER)
            ->whereKey($sourceId)
            ->first();

        if (! $source instanceof ImportFeedSource) {
            return null;
        }

        return $this->formatLabel($source);
    }

    /**
     * @return array{
     *     source: string,
     *     source_type: string,
     *     source_label: string,
     *     source_id: int,
     *     source_url: string|null,
     *     stored_path: string|null
     * }|null
     */
    public function resolveFromHistoryId(?int $sourceId): ?array
    {
        if ($sourceId === null || $sourceId <= 0) {
            return null;
        }

        $source = ImportFeedSource::query()
            ->where('supplier', self::SUPPLIER)
            ->whereKey($sourceId)
            ->first();

        if (! $source instanceof ImportFeedSource) {
            return null;
        }

        if ($source->source_type === self::SOURCE_TYPE_URL) {
            $url = trim((string) ($source->source_url ?? ''));

            if ($url === '') {
                return null;
            }

            return [
                'source' => $url,
                'source_type' => self::SOURCE_TYPE_URL,
                'source_label' => $this->formatLabel($source),
                'source_id' => (int) $source->id,
                'source_url' => $url,
                'stored_path' => null,
            ];
        }

        $storedPath = trim((string) ($source->stored_path ?? ''));

        if ($storedPath === '' || ! Storage::disk(self::STORAGE_DISK)->exists($storedPath)) {
            return null;
        }

        return [
            'source' => Storage::disk(self::STORAGE_DISK)->path($storedPath),
            'source_type' => self::SOURCE_TYPE_UPLOAD,
            'source_label' => $this->formatLabel($source),
            'source_id' => (int) $source->id,
            'source_url' => null,
            'stored_path' => $storedPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function rememberValidUrl(
        string $url,
        ?int $userId = null,
        ?int $runId = null,
        array $meta = [],
    ): ImportFeedSource {
        $this->pruneExpired();

        $normalizedUrl = $this->normalizeUrl($url);

        if ($normalizedUrl === '') {
            throw new RuntimeException('Feed URL cannot be empty.');
        }

        $fingerprint = hash('sha256', self::SOURCE_TYPE_URL.'|'.$normalizedUrl);
        $now = now();

        $source = ImportFeedSource::query()->firstOrNew([
            'supplier' => self::SUPPLIER,
            'fingerprint' => $fingerprint,
        ]);

        $source->fill([
            'source_type' => self::SOURCE_TYPE_URL,
            'source_url' => $normalizedUrl,
            'stored_path' => null,
            'original_filename' => null,
            'content_hash' => null,
            'size_bytes' => null,
            'last_used_at' => $now,
            'last_validated_at' => $now,
            'last_run_id' => $runId,
            'meta' => $this->mergeMeta($source, $meta),
        ]);

        if ($source->created_by === null && $userId !== null && $userId > 0) {
            $source->created_by = $userId;
        }

        $source->save();

        return $source;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function rememberValidUploadedPath(
        string $storedPath,
        ?string $originalFilename = null,
        ?int $userId = null,
        ?int $runId = null,
        array $meta = [],
    ): ImportFeedSource {
        $this->pruneExpired();

        $storedPath = ltrim(trim($storedPath), '/');

        if ($storedPath === '') {
            throw new RuntimeException('Feed file path cannot be empty.');
        }

        $disk = Storage::disk(self::STORAGE_DISK);

        if (! $disk->exists($storedPath)) {
            throw new RuntimeException('Uploaded feed file not found on storage disk.');
        }

        $absolutePath = $disk->path($storedPath);
        $contentHash = hash_file('sha256', $absolutePath);

        if (! is_string($contentHash) || $contentHash === '') {
            throw new RuntimeException('Unable to calculate uploaded feed checksum.');
        }

        $extension = $this->resolveUploadExtension($storedPath, $originalFilename);
        $dedupedPath = self::DEDUPED_UPLOAD_DIRECTORY.'/'.$contentHash.'.'.$extension;

        if (! $disk->exists($dedupedPath)) {
            $sourceAbsolute = $disk->path($storedPath);
            $targetAbsolute = $disk->path($dedupedPath);
            $targetDir = dirname($targetAbsolute);

            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            if (! @copy($sourceAbsolute, $targetAbsolute)) {
                throw new RuntimeException('Unable to store uploaded feed file.');
            }
        }

        if (
            $storedPath !== $dedupedPath
            && str_starts_with($storedPath, self::TEMP_UPLOAD_DIRECTORY.'/')
            && $disk->exists($storedPath)
        ) {
            $disk->delete($storedPath);
        }

        $fingerprint = hash('sha256', self::SOURCE_TYPE_UPLOAD.'|'.$contentHash);
        $now = now();

        $source = ImportFeedSource::query()->firstOrNew([
            'supplier' => self::SUPPLIER,
            'fingerprint' => $fingerprint,
        ]);

        $source->fill([
            'source_type' => self::SOURCE_TYPE_UPLOAD,
            'source_url' => null,
            'stored_path' => $dedupedPath,
            'original_filename' => $originalFilename ?: ($source->original_filename ?: basename($dedupedPath)),
            'content_hash' => $contentHash,
            'size_bytes' => @filesize($disk->path($dedupedPath)) ?: null,
            'last_used_at' => $now,
            'last_validated_at' => $now,
            'last_run_id' => $runId,
            'meta' => $this->mergeMeta($source, $meta),
        ]);

        if ($source->created_by === null && $userId !== null && $userId > 0) {
            $source->created_by = $userId;
        }

        $source->save();

        return $source;
    }

    public function markUsedById(int $sourceId, ?int $runId = null): ?ImportFeedSource
    {
        if ($sourceId <= 0) {
            return null;
        }

        $source = ImportFeedSource::query()
            ->where('supplier', self::SUPPLIER)
            ->whereKey($sourceId)
            ->first();

        if (! $source instanceof ImportFeedSource) {
            return null;
        }

        $source->last_used_at = now();

        if ($runId !== null && $runId > 0) {
            $source->last_run_id = $runId;
        }

        $source->save();

        return $source;
    }

    public function pruneExpired(): void
    {
        $threshold = now()->subDays(self::RETENTION_DAYS);
        $expired = ImportFeedSource::query()
            ->where('supplier', self::SUPPLIER)
            ->where(function ($query) use ($threshold): void {
                $query
                    ->where('last_used_at', '<', $threshold)
                    ->orWhere(function ($inner) use ($threshold): void {
                        $inner->whereNull('last_used_at')
                            ->where('created_at', '<', $threshold);
                    });
            })
            ->get();

        if ($expired->isEmpty()) {
            return;
        }

        $disk = Storage::disk(self::STORAGE_DISK);

        foreach ($expired as $source) {
            if ($source->source_type === self::SOURCE_TYPE_UPLOAD && is_string($source->stored_path) && $source->stored_path !== '') {
                $hasOtherRefs = ImportFeedSource::query()
                    ->where('supplier', self::SUPPLIER)
                    ->where('stored_path', $source->stored_path)
                    ->where('id', '!=', $source->id)
                    ->exists();

                if (! $hasOtherRefs && $disk->exists($source->stored_path)) {
                    $disk->delete($source->stored_path);
                }
            }

            $source->delete();
        }
    }

    public function formatLabel(ImportFeedSource $source): string
    {
        $prefix = $source->source_type === self::SOURCE_TYPE_UPLOAD ? 'FILE' : 'URL';
        $label = $source->source_type === self::SOURCE_TYPE_UPLOAD
            ? trim((string) ($source->original_filename ?: basename((string) $source->stored_path)))
            : trim((string) ($source->source_url ?? ''));

        if ($label === '') {
            $label = '#'.$source->id;
        }

        $usedAt = $source->last_used_at?->setTimezone('Europe/Moscow')->format('Y-m-d H:i');

        if ($usedAt === null) {
            return "[{$prefix}] {$label}";
        }

        return "[{$prefix}] {$label} · {$usedAt}";
    }

    public static function temporaryUploadDirectory(): string
    {
        return self::TEMP_UPLOAD_DIRECTORY;
    }

    public static function maxUploadSizeKilobytes(): int
    {
        return max(1, (int) config('catalog-import.feed_upload.max_size_kb', 50 * 1024));
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        if ($scheme !== '' && $host !== '') {
            return "{$scheme}://{$host}{$port}{$path}{$query}{$fragment}";
        }

        return $url;
    }

    private function resolveUploadExtension(string $storedPath, ?string $originalFilename): string
    {
        $candidates = [
            $originalFilename,
            $storedPath,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));

            if ($extension !== '') {
                return $extension;
            }
        }

        return 'xml';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function mergeMeta(ImportFeedSource $source, array $meta): array
    {
        $existing = $source->meta;

        if (! is_array($existing)) {
            $existing = [];
        }

        return array_merge($existing, $meta);
    }
}
