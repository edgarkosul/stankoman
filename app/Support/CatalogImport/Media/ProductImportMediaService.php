<?php

namespace App\Support\CatalogImport\Media;

use App\Jobs\DownloadProductImportMediaJob;
use App\Models\ImportMediaIssue;
use App\Models\Product;
use App\Models\ProductImportMedia;
use Illuminate\Support\Facades\Storage;

final class ProductImportMediaService
{
    /**
     * @param  array<int, string>  $sourceUrls
     * @return array{queued:int,reused:int,deduplicated:int,pending_media_ids:array<int, int>}
     */
    public function enqueueProductMedia(Product $product, array $sourceUrls, ?int $runId = null): array
    {
        $queued = 0;
        $reused = 0;
        $deduplicated = 0;
        $pendingMediaIds = [];

        foreach ($this->normalizeSourceUrls($sourceUrls) as $sourceUrl) {
            $sourceUrlHash = hash('sha256', $sourceUrl);

            $existingForProduct = ProductImportMedia::query()
                ->where('product_id', $product->id)
                ->where('source_url_hash', $sourceUrlHash)
                ->first();

            if ($existingForProduct instanceof ProductImportMedia) {
                $deduplicated++;

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

            if ($reusable instanceof ProductImportMedia && is_string($reusable->local_path)) {
                $this->completeMedia(
                    media: $media,
                    localPath: $reusable->local_path,
                    mimeType: $reusable->mime_type,
                    bytes: $reusable->bytes,
                    contentHash: $reusable->content_hash,
                    sourceKind: $reusable->source_kind,
                );

                $reused++;

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

    public function completeMedia(
        ProductImportMedia $media,
        string $localPath,
        ?string $mimeType,
        ?int $bytes,
        ?string $contentHash = null,
        ?string $sourceKind = null,
    ): ProductImportMedia {
        $media->status = ProductImportMedia::STATUS_COMPLETED;
        $media->local_path = $localPath;
        $media->mime_type = $mimeType;
        $media->bytes = $bytes;
        $media->content_hash = $contentHash;
        $media->source_kind = $sourceKind ?? $media->source_kind;
        $media->processed_at = now();
        $media->last_error = null;
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
}
