<?php

namespace App\Support\Metalmaster;

use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\Html\HtmlDocumentParser;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Sources\SourceResolver;
use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierAdapter;
use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierProfile;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class MetalmasterProductImportService
{
    public function __construct(
        private MetalmasterSupplierAdapter $adapter,
        private MetalmasterSupplierProfile $profile,
        private HtmlDocumentParser $recordParser,
        private ProductImportProcessor $processor,
        private SourceResolverInterface $sourceResolver = new SourceResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @param  null|callable(string, string|array<string, mixed>): void  $output
     * @param  null|callable(array<string, int|bool>): void  $progress
     * @return array{
     *     options: array<string, mixed>,
     *     write_mode: bool,
     *     found_urls: int,
     *     processed: int,
     *     errors: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     images_downloaded: int,
     *     image_download_failed: int,
     *     derivatives_queued: int,
     *     samples: array<int, array<string, string>>,
     *     url_errors: array<int, array{url: string, message: string}>,
     *     fatal_error: string|null,
     *     no_urls: bool,
     *     success: bool
     * }
     */
    public function run(array $options = [], ?callable $output = null, ?callable $progress = null): array
    {
        $normalized = $this->normalizeOptions($options);

        try {
            $targets = $this->loadTargets(
                $normalized['buckets_file'],
                $normalized['bucket'],
                $normalized['limit'],
            );
        } catch (Throwable $exception) {
            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: 0,
                processed: 0,
                errors: 0,
                created: 0,
                updated: 0,
                skipped: 0,
                imagesDownloaded: 0,
                imageDownloadFailed: 0,
                derivativesQueued: 0,
                noUrls: false,
            ));

            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'samples' => [],
                'url_errors' => [],
                'fatal_error' => $exception->getMessage(),
                'no_urls' => false,
                'success' => false,
            ];
        }

        if ($targets->isEmpty()) {
            $this->emit($output, 'warn', 'Подходящие URL товаров не найдены.');
            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: 0,
                processed: 0,
                errors: 0,
                created: 0,
                updated: 0,
                skipped: 0,
                imagesDownloaded: 0,
                imageDownloadFailed: 0,
                derivativesQueued: 0,
                noUrls: true,
            ));

            return [
                'options' => $normalized,
                'write_mode' => $normalized['write'],
                'found_urls' => 0,
                'processed' => 0,
                'errors' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'images_downloaded' => 0,
                'image_download_failed' => 0,
                'derivatives_queued' => 0,
                'samples' => [],
                'url_errors' => [],
                'fatal_error' => null,
                'no_urls' => true,
                'success' => true,
            ];
        }

        $this->emit($output, 'info', 'Найдено URL товаров: '.$targets->count());
        $this->emit($output, 'line', 'Режим: '.($normalized['write'] ? 'write' : 'dry-run'));

        $foundUrls = $targets->count();
        $processed = 0;
        $errors = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $imagesDownloaded = 0;
        $imageDownloadFailed = 0;
        $derivativesQueued = 0;
        $samples = [];
        $urlErrors = [];

        $this->emitProgress($progress, $this->makeProgressPayload(
            foundUrls: $foundUrls,
            processed: 0,
            errors: 0,
            created: 0,
            updated: 0,
            skipped: 0,
            imagesDownloaded: $imagesDownloaded,
            imageDownloadFailed: $imageDownloadFailed,
            derivativesQueued: $derivativesQueued,
            noUrls: false,
        ));

        foreach ($targets as $target) {
            $url = (string) $target['url'];
            $bucket = (string) $target['bucket'];

            try {
                $recordResult = $this->mapTargetToPayload($url, $bucket, $normalized);

                if ($recordResult['payload'] === null) {
                    $errors += $recordResult['errors_count'];

                    $urlErrors[] = [
                        'url' => $url,
                        'message' => $recordResult['first_error'] ?? 'Record mapping failed.',
                    ];

                    $this->emit($output, 'error', 'ERR: '.$url.' | '.($recordResult['first_error'] ?? 'Record mapping failed.'));

                    $this->emitProgress($progress, $this->makeProgressPayload(
                        foundUrls: $foundUrls,
                        processed: $processed,
                        errors: $errors,
                        created: $created,
                        updated: $updated,
                        skipped: $skipped,
                        imagesDownloaded: $imagesDownloaded,
                        imageDownloadFailed: $imageDownloadFailed,
                        derivativesQueued: $derivativesQueued,
                        noUrls: false,
                    ));

                    continue;
                }

                $errors += $recordResult['errors_count'];

                if ($normalized['write']) {
                    $processResult = $this->processor->process(
                        $recordResult['payload'],
                        $this->processorOptions($normalized),
                    );

                    if ($processResult->operation === 'created') {
                        $created++;
                    } elseif ($processResult->operation === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    $processed++;
                    $errors += count($processResult->errors);

                    $imagesDownloaded += (int) ($processResult->meta['media_reused'] ?? 0);
                    $derivativesQueued += (int) ($processResult->meta['media_queued'] ?? 0);
                    $imageDownloadFailed += $this->countMediaErrors($processResult->errors);
                } elseif (count($samples) < $normalized['show_samples']) {
                    $samples[] = $this->sampleRow($recordResult['payload'], $url, $bucket);
                    $processed++;
                } else {
                    $processed++;
                }

                $this->emit($output, 'line', 'OK: '.$url);

                if ($normalized['delay_ms'] > 0) {
                    usleep($normalized['delay_ms'] * 1000);
                }
            } catch (Throwable $exception) {
                $errors++;
                $urlErrors[] = [
                    'url' => $url,
                    'message' => $exception->getMessage(),
                ];

                $this->emit($output, 'error', 'ERR: '.$url.' | '.$exception->getMessage());
            }

            $this->emitProgress($progress, $this->makeProgressPayload(
                foundUrls: $foundUrls,
                processed: $processed,
                errors: $errors,
                created: $created,
                updated: $updated,
                skipped: $skipped,
                imagesDownloaded: $imagesDownloaded,
                imageDownloadFailed: $imageDownloadFailed,
                derivativesQueued: $derivativesQueued,
                noUrls: false,
            ));
        }

        if ($normalized['write'] && $normalized['run_id'] !== null) {
            $this->processor->finalizeMissing(
                supplier: $this->profile->supplierKey(),
                runId: $normalized['run_id'],
                options: [
                    'mode' => $normalized['mode'],
                    'finalize_missing' => $normalized['finalize_missing'],
                ],
            );
        }

        $this->emit($output, 'new_line', '');
        $this->emit($output, 'info', 'Итого: processed='.$processed.', errors='.$errors.'.');

        if ($normalized['write']) {
            $this->emit($output, 'line', 'DB: created='.$created.', updated='.$updated.', skipped='.$skipped.'.');

            if ($normalized['download_images']) {
                $this->emit(
                    $output,
                    'line',
                    'Images: downloaded='.$imagesDownloaded
                    .', failed='.$imageDownloadFailed
                    .', derivatives_queued='.$derivativesQueued
                    .'.'
                );
            }
        }

        if (! $normalized['write'] && $samples !== []) {
            $this->emit($output, 'new_line', '');
            $this->emit($output, 'table', [
                'headers' => ['url', 'bucket', 'title', 'price', 'brand', 'images', 'specs'],
                'rows' => $samples,
            ]);
        }

        return [
            'options' => $normalized,
            'write_mode' => $normalized['write'],
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'images_downloaded' => $imagesDownloaded,
            'image_download_failed' => $imageDownloadFailed,
            'derivatives_queued' => $derivativesQueued,
            'samples' => $samples,
            'url_errors' => $urlErrors,
            'fatal_error' => null,
            'no_urls' => false,
            'success' => $processed > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     buckets_file: string,
     *     bucket: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     run_id: int|null,
     *     mode: string,
     *     finalize_missing: bool
     * }
     */
    private function normalizeOptions(array $options): array
    {
        $runId = $options['run_id'] ?? null;

        return [
            'buckets_file' => trim((string) (
                $options['buckets_file']
                ?? $options['buckets-file']
                ?? storage_path('app/parser/metalmaster-buckets.json')
            )),
            'bucket' => trim((string) ($options['bucket'] ?? '')),
            'limit' => max(0, (int) ($options['limit'] ?? 0)),
            'timeout' => max(1, (int) ($options['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) (
                $options['delay_ms']
                ?? $options['delay-ms']
                ?? $options['sleep']
                ?? 250
            )),
            'write' => (bool) ($options['write'] ?? true),
            'publish' => (bool) ($options['publish'] ?? false),
            'download_images' => (bool) ($options['download_images'] ?? $options['download-images'] ?? false),
            'skip_existing' => (bool) ($options['skip_existing'] ?? $options['skip-existing'] ?? false),
            'show_samples' => max(0, (int) ($options['show_samples'] ?? $options['show-samples'] ?? 3)),
            'run_id' => is_numeric($runId) ? (int) $runId : null,
            'mode' => (string) ($options['mode'] ?? 'partial_import'),
            'finalize_missing' => (bool) ($options['finalize_missing'] ?? true),
        ];
    }

    /**
     * @return Collection<int, array{bucket: string, url: string}>
     */
    private function loadTargets(string $bucketsFile, string $bucketFilter, int $limit): Collection
    {
        if (! is_file($bucketsFile)) {
            throw new RuntimeException("Buckets file not found: {$bucketsFile}");
        }

        $raw = file_get_contents($bucketsFile);

        if (! is_string($raw)) {
            throw new RuntimeException("Unable to read buckets file: {$bucketsFile}");
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid buckets JSON: {$bucketsFile}");
        }

        $bucketRows = $this->extractBucketRows($decoded);

        $targets = collect($bucketRows)
            ->filter(function (mixed $row) use ($bucketFilter): bool {
                if (! is_array($row)) {
                    return false;
                }

                if ($bucketFilter === '') {
                    return true;
                }

                return (string) ($row['bucket'] ?? '') === $bucketFilter;
            })
            ->flatMap(function (array $row): Collection {
                $bucket = (string) ($row['bucket'] ?? '');
                $urls = is_array($row['product_urls'] ?? null) ? $row['product_urls'] : [];

                return collect($urls)->map(fn (mixed $url): array => [
                    'bucket' => $bucket,
                    'url' => (string) $url,
                ]);
            })
            ->filter(fn (array $target): bool => filter_var($target['url'], FILTER_VALIDATE_URL) !== false)
            ->unique('url')
            ->values();

        if ($limit > 0) {
            $targets = $targets->take($limit)->values();
        }

        return $targets;
    }

    /**
     * @param  array<string|int, mixed>  $decoded
     * @return array<int, array<string, mixed>>
     */
    private function extractBucketRows(array $decoded): array
    {
        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        $rows = $decoded['buckets'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{payload:\App\Support\CatalogImport\DTO\ProductPayload|null,errors_count:int,first_error:string|null}
     */
    private function mapTargetToPayload(string $url, string $bucket, array $normalized): array
    {
        $source = $this->sourceResolver->resolve($url, [
            'cache_key' => $this->profile->supplierKey().'_'.sha1($url),
            'timeout' => $normalized['timeout'],
            'connect_timeout' => min(10, max(1, $normalized['timeout'])),
            'retry_times' => 2,
            'retry_sleep_ms' => 300,
        ]);

        $records = $this->recordParser->parse($source, array_merge(
            $this->profile->parserOptions(),
            [
                'url' => $url,
                'meta' => [
                    'supplier' => $this->profile->supplierKey(),
                    'bucket' => $bucket,
                ],
            ],
        ));

        foreach ($records as $record) {
            $mapping = $this->adapter->mapRecord($record);

            return [
                'payload' => $mapping->payload,
                'errors_count' => count($mapping->errors),
                'first_error' => $mapping->errors[0]->message ?? null,
            ];
        }

        return [
            'payload' => null,
            'errors_count' => 1,
            'first_error' => 'HTML parser returned no records.',
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function processorOptions(array $normalized): array
    {
        return [
            'supplier' => $this->profile->supplierKey(),
            'run_id' => $normalized['run_id'],
            'create_missing' => true,
            'update_existing' => ! $normalized['skip_existing'],
            'publish_created' => $normalized['publish'],
            'publish_updated' => $normalized['publish'],
            'download_media' => $normalized['download_images'],
            'legacy_match' => $this->profile->defaults()['legacy_match'] ?? null,
            'use_source_slug' => true,
            'mode' => $normalized['mode'],
        ];
    }

    /**
     * @param  array<int, \App\Support\CatalogImport\DTO\ImportError>  $errors
     */
    private function countMediaErrors(array $errors): int
    {
        return collect($errors)
            ->filter(fn ($error): bool => str_starts_with((string) ($error->code ?? ''), 'media_'))
            ->count();
    }

    /**
     * @return array{url: string, bucket: string, title: string, price: string, brand: string, images: string, specs: string}
     */
    private function sampleRow(\App\Support\CatalogImport\DTO\ProductPayload $payload, string $url, string $bucket): array
    {
        return [
            'url' => $url,
            'bucket' => $bucket,
            'title' => (string) ($payload->title ?? $payload->name),
            'price' => (string) ($payload->priceAmount ?? 0),
            'brand' => (string) ($payload->brand ?? ''),
            'images' => (string) count($payload->images),
            'specs' => (string) count($payload->attributes),
        ];
    }

    /**
     * @param  null|callable(string, string|array<string, mixed>): void  $output
     */
    private function emit(?callable $output, string $type, string|array $payload): void
    {
        if ($output === null) {
            return;
        }

        $output($type, $payload);
    }

    /**
     * @param  null|callable(array<string, int|bool>): void  $progress
     * @param  array<string, int|bool>  $payload
     */
    private function emitProgress(?callable $progress, array $payload): void
    {
        if ($progress === null) {
            return;
        }

        $progress($payload);
    }

    /**
     * @return array<string, int|bool>
     */
    private function makeProgressPayload(
        int $foundUrls,
        int $processed,
        int $errors,
        int $created,
        int $updated,
        int $skipped,
        int $imagesDownloaded,
        int $imageDownloadFailed,
        int $derivativesQueued,
        bool $noUrls,
    ): array {
        return [
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'errors' => $errors,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'images_downloaded' => $imagesDownloaded,
            'image_download_failed' => $imageDownloadFailed,
            'derivatives_queued' => $derivativesQueued,
            'no_urls' => $noUrls,
        ];
    }
}
