<?php

namespace App\Support\CatalogImport\Media;

use App\Jobs\DownloadProductImportMediaJob;
use App\Models\ImportMediaIssue;
use App\Models\Product;
use App\Models\ProductImportMedia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProductImportMediaService
{
    /**
     * @param  array<int, string>  $sourceUrls
     * @return array{queued:int,reused:int,deduplicated:int,pending_media_ids:array<int, int>}
     */
    public function enqueueProductMedia(
        Product $product,
        array $sourceUrls,
        ?int $runId = null,
        bool $forceRecheck = false,
    ): array {
        $queued = 0;
        $reused = 0;
        $deduplicated = 0;
        $pendingMediaIds = [];

        foreach ($this->normalizeSourceUrls($sourceUrls) as $sourceUrl) {
            $sourceUrlHash = hash('sha256', $sourceUrl);

            $existingForProduct = ProductImportMedia::query()
                ->where('product_id', $product->id)
                ->where('source_url_hash', $sourceUrlHash)
                ->orderByDesc('id')
                ->first();

            if ($existingForProduct instanceof ProductImportMedia) {
                if ($this->hasUsableCompletedLocalPath($existingForProduct)) {
                    if ($this->canReuseCompletedMedia($existingForProduct, $forceRecheck)) {
                        $reused++;
                        $this->syncProductImageFields($product->id);

                        continue;
                    }

                    $this->prepareMediaForRecheck(
                        media: $existingForProduct,
                        sourceUrl: $sourceUrl,
                        runId: $runId,
                        forceRecheck: $forceRecheck,
                        seedMedia: $existingForProduct,
                    );

                    $queued++;
                    $pendingMediaIds[] = $existingForProduct->id;

                    continue;
                }

                if (in_array($existingForProduct->status, [
                    ProductImportMedia::STATUS_PENDING,
                    ProductImportMedia::STATUS_PROCESSING,
                ], true)) {
                    $deduplicated++;

                    continue;
                }

                $this->resetMediaAsPending(
                    media: $existingForProduct,
                    sourceUrl: $sourceUrl,
                    runId: $runId,
                );

                $queued++;
                $pendingMediaIds[] = $existingForProduct->id;

                continue;
            }

            $media = ProductImportMedia::query()->create([
                'run_id' => $runId,
                'product_id' => $product->id,
                'source_url' => $sourceUrl,
                'source_url_hash' => $sourceUrlHash,
                'source_kind' => $this->guessSourceKindFromUrl($sourceUrl),
                'status' => ProductImportMedia::STATUS_PENDING,
                'attempts' => 0,
            ]);

            $reusable = $this->findReusableCompletedBySourceHash($sourceUrlHash, $media->id);

            if ($reusable instanceof ProductImportMedia && $this->hasUsableCompletedLocalPath($reusable)) {
                if ($this->canReuseCompletedMedia($reusable, $forceRecheck)) {
                    $this->completeMedia(
                        media: $media,
                        localPath: (string) $reusable->local_path,
                        mimeType: $reusable->mime_type,
                        bytes: $reusable->bytes,
                        contentHash: $reusable->content_hash,
                        sourceKind: $reusable->source_kind,
                        meta: $this->completionMetaFromExistingMedia($reusable),
                    );

                    $reused++;

                    continue;
                }

                $this->prepareMediaForRecheck(
                    media: $media,
                    sourceUrl: $sourceUrl,
                    runId: $runId,
                    forceRecheck: $forceRecheck,
                    seedMedia: $reusable,
                );

                $queued++;
                $pendingMediaIds[] = $media->id;

                continue;
            }

            $queued++;
            $pendingMediaIds[] = $media->id;
        }

        return [
            'queued' => $queued,
            'reused' => $reused,
            'deduplicated' => $deduplicated,
            'pending_media_ids' => $pendingMediaIds,
        ];
    }

    /**
     * @param  array<int, int>  $mediaIds
     */
    public function dispatchPendingMedia(array $mediaIds): void
    {
        $queue = $this->queueName();

        foreach (array_values(array_unique(array_filter($mediaIds, fn (mixed $id): bool => is_int($id) || (is_string($id) && preg_match('/^[0-9]+$/', $id) === 1)))) as $mediaId) {
            DownloadProductImportMediaJob::dispatch((int) $mediaId)
                ->onQueue($queue)
                ->afterCommit();
        }
    }

    public function queueName(): string
    {
        $queue = (string) config('catalog-import.media.queue', 'default');

        return $queue !== '' ? $queue : 'default';
    }

    public function mediaDiskName(): string
    {
        $disk = (string) config('catalog-import.media.disk', 'public');

        return $disk !== '' ? $disk : 'public';
    }

    public function mediaStorageFolder(): string
    {
        $folder = trim((string) config('catalog-import.media.storage_folder', 'pics/import'), '/');

        return $folder !== '' ? $folder : 'pics/import';
    }

    public function maxBytes(): int
    {
        $maxBytes = (int) config('catalog-import.media.max_bytes', 10 * 1024 * 1024);

        return $maxBytes > 0 ? $maxBytes : 10 * 1024 * 1024;
    }

    public function recheckTtlSeconds(): int
    {
        $ttl = (int) config('catalog-import.media.recheck_ttl_seconds', 7 * 24 * 60 * 60);

        return max(0, $ttl);
    }

    public function useConditionalHeadersForRecheck(): bool
    {
        return (bool) config('catalog-import.media.use_conditional_headers_for_recheck', true);
    }

    /**
     * @return array<int, int>
     */
    public function retryDelaysMs(): array
    {
        $delays = config('catalog-import.media.retry_delays_ms', [250, 750, 1500]);

        if (! is_array($delays) || $delays === []) {
            return [250, 750, 1500];
        }

        $normalized = [];

        foreach ($delays as $delay) {
            if (is_int($delay) && $delay >= 0) {
                $normalized[] = $delay;

                continue;
            }

            if (is_string($delay) && preg_match('/^[0-9]+$/', $delay) === 1) {
                $normalized[] = (int) $delay;
            }
        }

        return $normalized !== [] ? $normalized : [250, 750, 1500];
    }

    public function timeoutSeconds(): int
    {
        $timeout = (int) config('catalog-import.media.timeout_seconds', 25);

        return $timeout > 0 ? $timeout : 25;
    }

    /**
     * @return array<int, string>
     */
    public function allowedMimes(): array
    {
        $mimes = config('catalog-import.media.allowed_mimes', []);

        if (! is_array($mimes)) {
            return [];
        }

        $normalized = [];

        foreach ($mimes as $mime) {
            if (! is_string($mime)) {
                continue;
            }

            $mime = trim(strtolower($mime));

            if ($mime === '') {
                continue;
            }

            $normalized[] = $mime;
        }

        return array_values(array_unique($normalized));
    }

    public function findReusableCompletedBySourceHash(string $sourceUrlHash, ?int $exceptMediaId = null): ?ProductImportMedia
    {
        return $this->findReusableCompletedQuery($exceptMediaId)
            ->where('source_url_hash', $sourceUrlHash)
            ->first();
    }

    public function findReusableCompletedByContentHash(string $contentHash, ?int $exceptMediaId = null): ?ProductImportMedia
    {
        return $this->findReusableCompletedQuery($exceptMediaId)
            ->where('content_hash', $contentHash)
            ->first();
    }

    public function incrementAttempts(ProductImportMedia $media): ProductImportMedia
    {
        $media->attempts = (int) $media->attempts + 1;
        $media->save();

        return $media;
    }

    public function markAsProcessing(ProductImportMedia $media): ProductImportMedia
    {
        $media->status = ProductImportMedia::STATUS_PROCESSING;
        $media->save();

        return $media;
    }

    /**
     * @return array{
     *     required: bool,
     *     conditional: bool,
     *     etag: string|null,
     *     last_modified: string|null,
     *     previous_local_path: string|null,
     *     previous_content_hash: string|null,
     *     previous_mime_type: string|null,
     *     previous_bytes: int|null
     * }
     */
    public function resolveRecheckContext(ProductImportMedia $media): array
    {
        $meta = $this->normalizeMeta($media->meta);
        $recheck = is_array($meta['recheck'] ?? null) ? $meta['recheck'] : [];

        return [
            'required' => ($recheck['required'] ?? false) === true,
            'conditional' => ($recheck['conditional'] ?? false) === true && $this->useConditionalHeadersForRecheck(),
            'etag' => $this->normalizeString($recheck['etag'] ?? $meta['etag'] ?? null),
            'last_modified' => $this->normalizeString($recheck['last_modified'] ?? $meta['last_modified'] ?? null),
            'previous_local_path' => $this->normalizeString($recheck['previous_local_path'] ?? null),
            'previous_content_hash' => $this->normalizeString($recheck['previous_content_hash'] ?? null),
            'previous_mime_type' => $this->normalizeString($recheck['previous_mime_type'] ?? null),
            'previous_bytes' => $this->normalizeInt($recheck['previous_bytes'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function completionMetaFromExistingMedia(ProductImportMedia $media): array
    {
        $meta = $this->normalizeMeta($media->meta);

        $completedMeta = [
            'etag' => $this->normalizeString($meta['etag'] ?? null),
            'last_modified' => $this->normalizeString($meta['last_modified'] ?? null),
            'last_checked_at' => $this->normalizeString($meta['last_checked_at'] ?? null),
        ];

        if (($completedMeta['last_checked_at'] ?? null) === null && $media->processed_at !== null) {
            $completedMeta['last_checked_at'] = $media->processed_at->toAtomString();
        }

        return $this->pruneMeta($completedMeta);
    }

    public function canReuseCompletedMedia(ProductImportMedia $media, bool $forceRecheck = false): bool
    {
        if (! $this->hasUsableCompletedLocalPath($media)) {
            return false;
        }

        return ! $this->requiresRecheck($media, $forceRecheck);
    }

    public function completeMedia(
        ProductImportMedia $media,
        string $localPath,
        ?string $mimeType,
        ?int $bytes,
        ?string $contentHash = null,
        ?string $sourceKind = null,
        array $meta = [],
    ): ProductImportMedia {
        $media->status = ProductImportMedia::STATUS_COMPLETED;
        $media->local_path = $localPath;
        $media->mime_type = $mimeType;
        $media->bytes = $bytes;
        $media->content_hash = $contentHash;
        $media->source_kind = $sourceKind ?? $media->source_kind;
        $media->processed_at = now();
        $media->last_error = null;
        $media->meta = $this->mergeCompletionMeta($media, $meta);
        $media->save();

        if ($media->source_kind === 'image') {
            $this->syncProductImageFields($media->product_id);
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function failMedia(ProductImportMedia $media, string $code, string $message, array $context = []): ProductImportMedia
    {
        $media->status = ProductImportMedia::STATUS_FAILED;
        $media->processed_at = now();
        $media->last_error = $message;
        $media->save();

        $this->logIssue($media, $code, $message, $context);

        return $media;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function logIssue(ProductImportMedia $media, string $code, string $message, array $context = []): ImportMediaIssue
    {
        return ImportMediaIssue::query()->create([
            'media_id' => $media->id,
            'run_id' => $media->run_id,
            'product_id' => $media->product_id,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public function syncProductImageFields(int $productId): void
    {
        $imagePaths = ProductImportMedia::query()
            ->where('product_id', $productId)
            ->where('source_kind', 'image')
            ->where('status', ProductImportMedia::STATUS_COMPLETED)
            ->whereNotNull('local_path')
            ->orderBy('id')
            ->pluck('local_path')
            ->filter(fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->map(fn (string $path): string => trim($path))
            ->unique()
            ->values()
            ->all();

        if ($imagePaths === []) {
            return;
        }

        $product = Product::query()->find($productId);

        if (! $product instanceof Product) {
            return;
        }

        $product->fill([
            'image' => $imagePaths[0],
            'thumb' => $imagePaths[0],
            'gallery' => $imagePaths,
        ]);
        $product->save();
    }

    public function storagePathForHash(string $contentHash, string $extension): string
    {
        $folder = $this->mediaStorageFolder();
        $prefix = substr($contentHash, 0, 2);

        return $folder.'/'.$prefix.'/'.$contentHash.'.'.$extension;
    }

    public function pathExistsOnDisk(string $path): bool
    {
        return Storage::disk($this->mediaDiskName())->exists($path);
    }

    public function isMimeAllowed(string $mimeType): bool
    {
        $mimeType = trim(strtolower($mimeType));

        if ($mimeType === '') {
            return false;
        }

        foreach ($this->allowedMimes() as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }

            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);

                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findReusableCompletedQuery(?int $exceptMediaId = null)
    {
        return ProductImportMedia::query()
            ->where('status', ProductImportMedia::STATUS_COMPLETED)
            ->whereNotNull('local_path')
            ->when(
                $exceptMediaId !== null,
                fn ($query) => $query->where('id', '!=', $exceptMediaId),
            )
            ->orderByDesc('id');
    }

    private function hasUsableCompletedLocalPath(ProductImportMedia $media): bool
    {
        if ($media->status !== ProductImportMedia::STATUS_COMPLETED) {
            return false;
        }

        $localPath = $this->normalizeString($media->local_path);

        if ($localPath === null) {
            return false;
        }

        return $this->pathExistsOnDisk($localPath);
    }

    private function requiresRecheck(ProductImportMedia $media, bool $forceRecheck): bool
    {
        if ($forceRecheck) {
            return true;
        }

        $ttl = $this->recheckTtlSeconds();

        if ($ttl <= 0) {
            return false;
        }

        $lastCheckedAt = $this->resolveLastCheckedAt($media);

        if (! $lastCheckedAt instanceof CarbonImmutable) {
            return true;
        }

        return $lastCheckedAt->addSeconds($ttl)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    private function resolveLastCheckedAt(ProductImportMedia $media): ?CarbonImmutable
    {
        $meta = $this->normalizeMeta($media->meta);
        $fromMeta = $this->normalizeString($meta['last_checked_at'] ?? null);

        if ($fromMeta !== null) {
            try {
                return CarbonImmutable::parse($fromMeta);
            } catch (Throwable) {
                // ignore invalid metadata and fallback to processed_at
            }
        }

        return $media->processed_at?->toImmutable();
    }

    private function prepareMediaForRecheck(
        ProductImportMedia $media,
        string $sourceUrl,
        ?int $runId,
        bool $forceRecheck,
        ?ProductImportMedia $seedMedia = null,
    ): ProductImportMedia {
        $seed = $seedMedia instanceof ProductImportMedia ? $seedMedia : $media;
        $seedMeta = $this->normalizeMeta($seed->meta);

        $etag = $this->normalizeString($seedMeta['etag'] ?? null);
        $lastModified = $this->normalizeString($seedMeta['last_modified'] ?? null);
        $previousLocalPath = $this->normalizeString($seed->local_path);

        if ($previousLocalPath !== null && ! $this->pathExistsOnDisk($previousLocalPath)) {
            $previousLocalPath = null;
        }

        $conditional = $this->useConditionalHeadersForRecheck()
            && $previousLocalPath !== null
            && ($etag !== null || $lastModified !== null);

        $meta = [
            'etag' => $etag,
            'last_modified' => $lastModified,
            'recheck' => [
                'required' => true,
                'force' => $forceRecheck,
                'conditional' => $conditional,
                'requested_at' => now()->toAtomString(),
                'etag' => $etag,
                'last_modified' => $lastModified,
                'previous_local_path' => $previousLocalPath,
                'previous_content_hash' => $this->normalizeString($seed->content_hash),
                'previous_mime_type' => $this->normalizeString($seed->mime_type),
                'previous_bytes' => $this->normalizeInt($seed->bytes),
            ],
        ];

        return $this->resetMediaAsPending(
            media: $media,
            sourceUrl: $sourceUrl,
            runId: $runId,
            meta: $this->pruneMeta($meta),
        );
    }

    private function resetMediaAsPending(
        ProductImportMedia $media,
        string $sourceUrl,
        ?int $runId,
        ?array $meta = null,
    ): ProductImportMedia {
        $media->fill([
            'run_id' => $runId,
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'status' => ProductImportMedia::STATUS_PENDING,
            'source_kind' => $this->guessSourceKindFromUrl($sourceUrl),
            'mime_type' => null,
            'bytes' => null,
            'content_hash' => null,
            'local_path' => null,
            'attempts' => 0,
            'last_error' => null,
            'processed_at' => null,
            'meta' => $meta,
        ]);
        $media->save();

        return $media;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function mergeCompletionMeta(ProductImportMedia $media, array $meta): ?array
    {
        $merged = $this->normalizeMeta($media->meta);
        unset($merged['recheck']);

        foreach ($meta as $key => $value) {
            $merged[$key] = $value;
        }

        $merged = $this->pruneMeta($merged);

        return $merged !== [] ? $merged : null;
    }

    /**
     * @param  array<int, string>  $sourceUrls
     * @return array<int, string>
     */
    private function normalizeSourceUrls(array $sourceUrls): array
    {
        $normalized = [];

        foreach ($sourceUrls as $sourceUrl) {
            if (! is_string($sourceUrl)) {
                continue;
            }

            $sourceUrl = trim($sourceUrl);

            if ($sourceUrl === '') {
                continue;
            }

            $normalized[] = $sourceUrl;
        }

        return array_values(array_unique($normalized));
    }

    private function guessSourceKindFromUrl(string $sourceUrl): string
    {
        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'], true)) {
            return 'document';
        }

        return 'image';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMeta(mixed $meta): array
    {
        return is_array($meta) ? $meta : [];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function pruneMeta(array $meta): array
    {
        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $value = $this->pruneMeta($value);

                if ($value === []) {
                    unset($meta[$key]);

                    continue;
                }

                $meta[$key] = $value;

                continue;
            }

            if ($value === null || $value === '') {
                unset($meta[$key]);
            }
        }

        return $meta;
    }
}
