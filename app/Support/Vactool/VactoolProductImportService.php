<?php

namespace App\Support\Vactool;

use App\Models\ProductSupplierReference;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\Html\HtmlDocumentParser;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Sources\SourceResolver;
use App\Support\CatalogImport\Suppliers\Vactool\VactoolSupplierAdapter;
use App\Support\CatalogImport\Suppliers\Vactool\VactoolSupplierProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class VactoolProductImportService
{
    public function __construct(
        private VactoolSitemapCrawler $sitemapCrawler,
        private VactoolSupplierAdapter $adapter,
        private VactoolSupplierProfile $profile,
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
            $allUrls = $this->sitemapCrawler->collectUrls($normalized['sitemap']);
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

        $productUrls = $this->resolveProductUrls($allUrls, $normalized);

        if ($productUrls->isEmpty()) {
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

        $this->emit($output, 'info', 'Найдено URL товаров: '.$productUrls->count());
        $this->emit($output, 'line', 'Режим: '.($normalized['write'] ? 'write' : 'dry-run'));

        $foundUrls = $productUrls->count();
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
        $prefilteredExternalIds = $this->resolvePrefilteredExternalIds($productUrls, $normalized);

        if ($prefilteredExternalIds !== [] && $normalized['run_id'] !== null) {
            $this->touchPrefilteredReferences($prefilteredExternalIds, $normalized['run_id']);
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

        foreach ($productUrls as $url) {
            $externalId = $this->profile->resolveExternalId($url);

            if (isset($prefilteredExternalIds[$externalId])) {
                $processed++;
                $skipped++;

                $this->emit($output, 'line', 'SKIP: '.$url.' | existing product reference.');

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

            try {
                $recordResult = $this->mapUrlToPayload($url, $normalized);

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
                    $samples[] = $this->sampleRow($recordResult['payload'], $url);
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
                'headers' => ['url', 'title', 'price', 'brand', 'images', 'specs'],
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
     *     sitemap: string,
     *     match: string,
     *     limit: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     run_id: int|null,
     *     mode: string,
     *     finalize_missing: bool,
     *     create_missing: bool,
     *     update_existing: bool
     * }
     */
    private function normalizeOptions(array $options): array
    {
        $runId = $options['run_id'] ?? null;
        $skipExisting = $this->normalizeBoolOption(
            $options['skip_existing'] ?? $options['skip-existing'] ?? false,
            false,
        );
        $mode = $this->normalizeMode($options['mode'] ?? null);

        return [
            'sitemap' => (string) ($options['sitemap'] ?? 'https://vactool.ru/sitemap.xml'),
            'match' => (string) ($options['match'] ?? '/catalog/product-'),
            'limit' => max(0, (int) ($options['limit'] ?? 0)),
            'delay_ms' => max(0, (int) ($options['delay_ms'] ?? $options['delay-ms'] ?? 250)),
            'write' => $this->normalizeBoolOption($options['write'] ?? false, false),
            'publish' => $this->normalizeBoolOption($options['publish'] ?? false, false),
            'download_images' => $this->normalizeBoolOption(
                $options['download_images'] ?? $options['download-images'] ?? false,
                false,
            ),
            'skip_existing' => $skipExisting,
            'show_samples' => max(0, (int) ($options['show_samples'] ?? $options['show-samples'] ?? 3)),
            'run_id' => is_numeric($runId) ? (int) $runId : null,
            'mode' => $mode,
            'finalize_missing' => $this->normalizeBoolOption(
                $options['finalize_missing'] ?? $options['finalize-missing'] ?? null,
                $mode === 'full_sync_authoritative',
            ),
            'create_missing' => $this->normalizeBoolOption(
                $options['create_missing'] ?? $options['create-missing'] ?? null,
                true,
            ),
            'update_existing' => $this->normalizeBoolOption(
                $options['update_existing'] ?? $options['update-existing'] ?? null,
                ! $skipExisting,
            ),
        ];
    }

    /**
     * @param  array<int, string>  $allUrls
     * @param  array<string, mixed>  $normalized
     * @return Collection<int, string>
     */
    private function resolveProductUrls(array $allUrls, array $normalized): Collection
    {
        $productUrls = collect($allUrls)
            ->filter(function (mixed $url) use ($normalized): bool {
                if (! is_string($url)) {
                    return false;
                }

                return $normalized['match'] === '' || Str::contains($url, $normalized['match']);
            })
            ->unique()
            ->values();

        if ($normalized['limit'] > 0) {
            $productUrls = $productUrls->take($normalized['limit'])->values();
        }

        return $productUrls;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{payload:\App\Support\CatalogImport\DTO\ProductPayload|null,errors_count:int,first_error:string|null}
     */
    private function mapUrlToPayload(string $url, array $normalized): array
    {
        $source = $this->sourceResolver->resolve($url, [
            'cache_key' => $this->profile->supplierKey().'_'.sha1($url),
            'timeout' => 20,
            'connect_timeout' => 10,
            'retry_times' => 2,
            'retry_sleep_ms' => 300,
        ]);

        $records = $this->recordParser->parse($source, array_merge(
            $this->profile->parserOptions(),
            [
                'url' => $url,
                'meta' => ['supplier' => $this->profile->supplierKey()],
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
            'create_missing' => $normalized['create_missing'],
            'update_existing' => $normalized['update_existing'] && ! $normalized['skip_existing'],
            'publish_created' => $normalized['publish'],
            'publish_updated' => $normalized['publish'],
            'download_media' => $normalized['download_images'],
            'legacy_match' => $this->profile->defaults()['legacy_match'] ?? null,
            'use_source_slug' => false,
            'mode' => $normalized['mode'],
        ];
    }

    /**
     * @param  Collection<int, string>  $productUrls
     * @param  array<string, mixed>  $normalized
     * @return array<string, true>
     */
    private function resolvePrefilteredExternalIds(Collection $productUrls, array $normalized): array
    {
        if (! $normalized['write'] || ! $normalized['skip_existing']) {
            return [];
        }

        $externalIds = $productUrls
            ->map(fn (string $url): string => $this->profile->resolveExternalId($url))
            ->filter(fn (string $externalId): bool => trim($externalId) !== '')
            ->unique()
            ->values();

        if ($externalIds->isEmpty()) {
            return [];
        }

        return ProductSupplierReference::query()
            ->where('supplier', $this->profile->supplierKey())
            ->whereIn('external_id', $externalIds->all())
            ->pluck('external_id')
            ->filter(fn (mixed $externalId): bool => is_string($externalId) && $externalId !== '')
            ->mapWithKeys(fn (string $externalId): array => [$externalId => true])
            ->all();
    }

    /**
     * @param  array<string, true>  $prefilteredExternalIds
     */
    private function touchPrefilteredReferences(array $prefilteredExternalIds, int $runId): void
    {
        if ($prefilteredExternalIds === [] || $runId <= 0) {
            return;
        }

        ProductSupplierReference::query()
            ->where('supplier', $this->profile->supplierKey())
            ->whereIn('external_id', array_keys($prefilteredExternalIds))
            ->update([
                'last_seen_run_id' => $runId,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function normalizeMode(mixed $mode): string
    {
        if (! is_string($mode)) {
            return 'partial_import';
        }

        $mode = trim($mode);

        if (in_array($mode, ['partial_import', 'full_sync_authoritative'], true)) {
            return $mode;
        }

        return 'partial_import';
    }

    private function normalizeBoolOption(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
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
     * @return array{url: string, title: string, price: string, brand: string, images: string, specs: string}
     */
    private function sampleRow(\App\Support\CatalogImport\DTO\ProductPayload $payload, string $url): array
    {
        return [
            'url' => $url,
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
