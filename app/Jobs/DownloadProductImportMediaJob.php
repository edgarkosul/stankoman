<?php

namespace App\Jobs;

use App\Models\ProductImportMedia;
use App\Support\CatalogImport\Media\ProductImportMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadProductImportMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(public int $mediaId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->lockKey()))
                ->releaseAfter(5)
                ->expireAfter(180),
        ];
    }

    public function handle(ProductImportMediaService $mediaService): void
    {
        $media = ProductImportMedia::query()->find($this->mediaId);

        if (! $media instanceof ProductImportMedia) {
            return;
        }

        if ($media->status === ProductImportMedia::STATUS_COMPLETED) {
            return;
        }

        $mediaService->incrementAttempts($media);
        $mediaService->markAsProcessing($media);

        $recheckContext = $mediaService->resolveRecheckContext($media);

        if (($recheckContext['required'] ?? false) !== true) {
            $reusableBySource = $mediaService->findReusableCompletedBySourceHash($media->source_url_hash, $media->id);

            if (
                $reusableBySource instanceof ProductImportMedia
                && is_string($reusableBySource->local_path)
                && $mediaService->canReuseCompletedMedia($reusableBySource)
            ) {
                $mediaService->completeMedia(
                    media: $media,
                    localPath: $reusableBySource->local_path,
                    mimeType: $reusableBySource->mime_type,
                    bytes: $reusableBySource->bytes,
                    contentHash: $reusableBySource->content_hash,
                    sourceKind: $reusableBySource->source_kind,
                    meta: $mediaService->completionMetaFromExistingMedia($reusableBySource),
                );

                return;
            }
        }

        $sourceUrl = $this->normalizeSourceUrl($media->source_url);

        if ($sourceUrl === null) {
            $mediaService->failMedia(
                media: $media,
                code: 'invalid_source_url',
                message: 'Media source URL must be absolute HTTP(S) URL.',
                context: ['url' => $media->source_url],
            );

            return;
        }

        try {
            $request = Http::timeout($mediaService->timeoutSeconds())
                ->retry($mediaService->retryDelaysMs(), throw: false)
                ->accept('*/*');

            $request = $this->withConditionalRecheckHeaders($request, $recheckContext);
            $response = $request->get($sourceUrl);
        } catch (ConnectionException $exception) {
            $mediaService->failMedia(
                media: $media,
                code: 'connection_failed',
                message: $exception->getMessage(),
                context: [
                    'url' => $sourceUrl,
                    'recheck_required' => ($recheckContext['required'] ?? false) === true,
                    'conditional_recheck' => ($recheckContext['conditional'] ?? false) === true,
                ],
            );

            return;
        } catch (Throwable $exception) {
            $mediaService->failMedia(
                media: $media,
                code: 'download_failed',
                message: $exception->getMessage(),
                context: [
                    'url' => $sourceUrl,
                    'recheck_required' => ($recheckContext['required'] ?? false) === true,
                    'conditional_recheck' => ($recheckContext['conditional'] ?? false) === true,
                ],
            );

            return;
        }

        if ($response->status() === 304) {
            $previousLocalPath = $this->normalizeString($recheckContext['previous_local_path'] ?? null);

            if (
                ($recheckContext['required'] ?? false) !== true
                || $previousLocalPath === null
                || ! $mediaService->pathExistsOnDisk($previousLocalPath)
            ) {
                $mediaService->failMedia(
                    media: $media,
                    code: 'not_modified_without_local_copy',
                    message: 'Media source returned 304 but no local media copy is available.',
                    context: [
                        'url' => $sourceUrl,
                        'status' => 304,
                        'recheck_required' => ($recheckContext['required'] ?? false) === true,
                        'previous_local_path' => $previousLocalPath,
                    ],
                );

                return;
            }

            $mediaService->completeMedia(
                media: $media,
                localPath: $previousLocalPath,
                mimeType: $this->normalizeString($recheckContext['previous_mime_type'] ?? null),
                bytes: $this->normalizeInt($recheckContext['previous_bytes'] ?? null),
                contentHash: $this->normalizeString($recheckContext['previous_content_hash'] ?? null),
                sourceKind: $media->source_kind,
                meta: $this->completionMeta(
                    etag: $response->header('ETag'),
                    lastModified: $response->header('Last-Modified'),
                ),
            );

            return;
        }

        if (! $response->ok()) {
            $mediaService->failMedia(
                media: $media,
                code: 'http_error',
                message: sprintf('Media download failed with HTTP %d.', $response->status()),
                context: [
                    'url' => $sourceUrl,
                    'status' => $response->status(),
                    'recheck_required' => ($recheckContext['required'] ?? false) === true,
                ],
            );

            return;
        }

        $body = (string) $response->body();

        if ($body === '') {
            $mediaService->failMedia(
                media: $media,
                code: 'empty_body',
                message: 'Downloaded media body is empty.',
                context: ['url' => $sourceUrl],
            );

            return;
        }

        $bytes = strlen($body);

        if ($bytes > $mediaService->maxBytes()) {
            $mediaService->failMedia(
                media: $media,
                code: 'file_too_large',
                message: 'Downloaded media exceeds size limit.',
                context: [
                    'url' => $sourceUrl,
                    'bytes' => $bytes,
                    'max_bytes' => $mediaService->maxBytes(),
                ],
            );

            return;
        }

        $mimeType = $this->resolveMimeType($response->header('Content-Type'), $body);

        if (! $mediaService->isMimeAllowed($mimeType)) {
            $mediaService->failMedia(
                media: $media,
                code: 'unsupported_mime_type',
                message: 'Downloaded media has unsupported MIME type.',
                context: [
                    'url' => $sourceUrl,
                    'mime_type' => $mimeType,
                ],
            );

            return;
        }

        $contentHash = hash('sha256', $body);
        $sourceKind = $this->resolveSourceKind($mimeType);
        $previousLocalPath = $this->normalizeString($recheckContext['previous_local_path'] ?? null);
        $previousContentHash = $this->normalizeString($recheckContext['previous_content_hash'] ?? null);

        if (
            ($recheckContext['required'] ?? false) === true
            && $previousLocalPath !== null
            && $mediaService->pathExistsOnDisk($previousLocalPath)
            && $previousContentHash !== null
            && hash_equals($previousContentHash, $contentHash)
        ) {
            $mediaService->completeMedia(
                media: $media,
                localPath: $previousLocalPath,
                mimeType: $mimeType,
                bytes: $bytes,
                contentHash: $contentHash,
                sourceKind: $sourceKind,
                meta: $this->completionMeta(
                    etag: $response->header('ETag'),
                    lastModified: $response->header('Last-Modified'),
                ),
            );

            return;
        }

        $reusableByContentHash = $mediaService->findReusableCompletedByContentHash($contentHash, $media->id);

        if (
            $reusableByContentHash instanceof ProductImportMedia
            && is_string($reusableByContentHash->local_path)
            && $mediaService->pathExistsOnDisk($reusableByContentHash->local_path)
        ) {
            $mediaService->completeMedia(
                media: $media,
                localPath: $reusableByContentHash->local_path,
                mimeType: $mimeType,
                bytes: $bytes,
                contentHash: $contentHash,
                sourceKind: $sourceKind,
                meta: $this->completionMeta(
                    etag: $response->header('ETag'),
                    lastModified: $response->header('Last-Modified'),
                ),
            );

            return;
        }

        $extension = $this->extensionForMimeType($mimeType);

        if ($extension === null) {
            $mediaService->failMedia(
                media: $media,
                code: 'unsupported_extension',
                message: 'Unable to resolve file extension for media.',
                context: [
                    'url' => $sourceUrl,
                    'mime_type' => $mimeType,
                ],
            );

            return;
        }

        $storagePath = $mediaService->storagePathForHash($contentHash, $extension);
        $disk = Storage::disk($mediaService->mediaDiskName());

        if (! $disk->exists($storagePath)) {
            if (! $disk->put($storagePath, $body)) {
                $mediaService->failMedia(
                    media: $media,
                    code: 'storage_write_failed',
                    message: 'Unable to write media file to storage.',
                    context: [
                        'url' => $sourceUrl,
                        'path' => $storagePath,
                    ],
                );

                return;
            }
        }

        $mediaService->completeMedia(
            media: $media,
            localPath: $storagePath,
            mimeType: $mimeType,
            bytes: $bytes,
            contentHash: $contentHash,
            sourceKind: $sourceKind,
            meta: $this->completionMeta(
                etag: $response->header('ETag'),
                lastModified: $response->header('Last-Modified'),
            ),
        );

        if ($sourceKind === 'image') {
            GenerateImageDerivativesJob::dispatch($storagePath, false)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $media = ProductImportMedia::query()->find($this->mediaId);

        if (! $media instanceof ProductImportMedia) {
            return;
        }

        if ($media->status === ProductImportMedia::STATUS_COMPLETED) {
            return;
        }

        $service = app(ProductImportMediaService::class);

        $service->failMedia(
            media: $media,
            code: 'job_failed',
            message: $exception?->getMessage() ?? 'Media job failed without exception message.',
            context: ['url' => $media->source_url],
        );
    }

    /**
     * @param  array{
     *     required: bool,
     *     conditional: bool,
     *     etag: string|null,
     *     last_modified: string|null,
     *     previous_local_path: string|null,
     *     previous_content_hash: string|null,
     *     previous_mime_type: string|null,
     *     previous_bytes: int|null
     * }  $recheck
     */
    private function withConditionalRecheckHeaders(PendingRequest $request, array $recheck): PendingRequest
    {
        if (($recheck['required'] ?? false) !== true || ($recheck['conditional'] ?? false) !== true) {
            return $request;
        }

        $headers = [];
        $etag = $this->normalizeString($recheck['etag'] ?? null);
        $lastModified = $this->normalizeString($recheck['last_modified'] ?? null);

        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        if ($headers === []) {
            return $request;
        }

        return $request->withHeaders($headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function completionMeta(mixed $etag, mixed $lastModified): array
    {
        $meta = [
            'etag' => $this->normalizeStringFromHeader($etag),
            'last_modified' => $this->normalizeStringFromHeader($lastModified),
            'last_checked_at' => now()->toAtomString(),
        ];

        foreach ($meta as $key => $value) {
            if ($value === null || $value === '') {
                unset($meta[$key]);
            }
        }

        return $meta;
    }

    private function lockKey(): string
    {
        $sourceUrlHash = ProductImportMedia::query()->whereKey($this->mediaId)->value('source_url_hash');

        if (is_string($sourceUrlHash) && $sourceUrlHash !== '') {
            return 'catalog-import-media-'.$sourceUrlHash;
        }

        return 'catalog-import-media-'.$this->mediaId;
    }

    private function resolveMimeType(mixed $contentType, string $body): string
    {
        if (is_string($contentType) && trim($contentType) !== '') {
            $parts = explode(';', strtolower(trim($contentType)));
            $candidate = trim((string) ($parts[0] ?? ''));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        $detected = @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $body);

        if (is_string($detected) && trim($detected) !== '') {
            return strtolower(trim($detected));
        }

        return 'application/octet-stream';
    }

    private function resolveSourceKind(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return 'document';
    }

    private function extensionForMimeType(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            default => null,
        };
    }

    private function normalizeSourceUrl(mixed $sourceUrl): ?string
    {
        if (! is_string($sourceUrl)) {
            return null;
        }

        $sourceUrl = trim($sourceUrl);

        if ($sourceUrl === '') {
            return null;
        }

        if (str_starts_with($sourceUrl, '//')) {
            $sourceUrl = 'https:'.$sourceUrl;
        }

        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($sourceUrl, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return null;
        }

        $scheme = strtolower($scheme);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $sourceUrl;
    }

    private function normalizeStringFromHeader(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $this->normalizeString($value);
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
}
