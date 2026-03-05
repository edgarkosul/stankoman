<?php

namespace App\Jobs;

use App\Models\ProductImportMedia;
use App\Support\CatalogImport\Media\ProductImportMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
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

        $reusableBySource = $mediaService->findReusableCompletedBySourceHash($media->source_url_hash, $media->id);

        if ($reusableBySource instanceof ProductImportMedia && is_string($reusableBySource->local_path)) {
            $mediaService->completeMedia(
                media: $media,
                localPath: $reusableBySource->local_path,
                mimeType: $reusableBySource->mime_type,
                bytes: $reusableBySource->bytes,
                contentHash: $reusableBySource->content_hash,
                sourceKind: $reusableBySource->source_kind,
            );

            return;
        }

        try {
            $response = Http::timeout($mediaService->timeoutSeconds())
                ->retry($mediaService->retryDelaysMs(), throw: false)
                ->accept('*/*')
                ->get($media->source_url);
        } catch (ConnectionException $exception) {
            $mediaService->failMedia(
                media: $media,
                code: 'connection_failed',
                message: $exception->getMessage(),
                context: ['url' => $media->source_url],
            );

            return;
        } catch (Throwable $exception) {
            $mediaService->failMedia(
                media: $media,
                code: 'download_failed',
                message: $exception->getMessage(),
                context: ['url' => $media->source_url],
            );

            return;
        }

        if (! $response->ok()) {
            $mediaService->failMedia(
                media: $media,
                code: 'http_error',
                message: sprintf('Media download failed with HTTP %d.', $response->status()),
                context: [
                    'url' => $media->source_url,
                    'status' => $response->status(),
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
                context: ['url' => $media->source_url],
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
                    'url' => $media->source_url,
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
                    'url' => $media->source_url,
                    'mime_type' => $mimeType,
                ],
            );

            return;
        }

        $contentHash = hash('sha256', $body);
        $sourceKind = $this->resolveSourceKind($mimeType);

        $reusableByContentHash = $mediaService->findReusableCompletedByContentHash($contentHash, $media->id);

        if ($reusableByContentHash instanceof ProductImportMedia && is_string($reusableByContentHash->local_path)) {
            $mediaService->completeMedia(
                media: $media,
                localPath: $reusableByContentHash->local_path,
                mimeType: $mimeType,
                bytes: $bytes,
                contentHash: $contentHash,
                sourceKind: $sourceKind,
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
                    'url' => $media->source_url,
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
                        'url' => $media->source_url,
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
}
