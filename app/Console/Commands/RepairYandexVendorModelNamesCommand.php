<?php

namespace App\Console\Commands;

use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Sources\SourceResolver;
use App\Support\CatalogImport\Yml\VendorModelOfferNameResolver;
use App\Support\CatalogImport\Yml\YandexMarketFeedProfile;
use App\Support\CatalogImport\Yml\YmlOfferRecord;
use App\Support\CatalogImport\Yml\YmlStreamParser;
use App\Support\NameNormalizer;
use App\Support\Products\ProductSearchSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Throwable;

class RepairYandexVendorModelNamesCommand extends Command
{
    protected $signature = 'catalog:repair-yandex-vendor-model-names
        {--supplier-id= : Supplier id from product_supplier_references}
        {--source= : Feed URL or local XML/YML path}
        {--source-id= : supplier_import_sources.id for source auto-detection}
        {--limit=0 : Maximum vendor.model offers to inspect}
        {--show-samples=10 : Number of planned changes to print}
        {--write : Actually update products in DB}';

    protected $description = 'Repair duplicated product/meta titles created by vendor.model feeds where model already contains vendor.';

    public function handle(
        SourceResolver $sourceResolver,
        YmlStreamParser $parser,
        VendorModelOfferNameResolver $nameResolver,
        ProductSearchSync $searchSync,
    ): int {
        $sourceRecord = $this->resolveSourceRecord();
        $supplierId = $this->resolveSupplierId($sourceRecord);

        if ($supplierId === null) {
            $this->error('Не указан supplier-id, и его не удалось определить по source-id.');

            return self::INVALID;
        }

        $source = $this->resolveSource($sourceRecord);

        if ($source === null) {
            $this->error('Не удалось определить источник feed. Передайте --source или корректный --source-id.');

            return self::INVALID;
        }

        $write = (bool) $this->option('write');
        $showSamples = max(0, (int) $this->option('show-samples'));

        try {
            $resolvedSource = $sourceResolver->resolve($source, [
                'cache_key' => YandexMarketFeedProfile::class.'_repair_'.sha1($source),
                'timeout' => 30,
                'connect_timeout' => 10,
                'retry_times' => 2,
                'retry_sleep_ms' => 300,
            ]);
        } catch (Throwable $exception) {
            $this->error('Не удалось подготовить источник: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Поставщик: #'.$supplierId);
        $this->line('Источник: '.$source);
        $this->line('Режим: '.($write ? 'write' : 'dry-run'));

        $corrections = $this->collectCorrections(
            parser: $parser,
            path: $resolvedSource->resolvedPath,
            nameResolver: $nameResolver,
        );

        if ($corrections['candidates'] === []) {
            $this->warn('В feed не найдено исправлений vendor.model.');
            $this->renderSummary(
                scanned: $corrections['scanned'],
                candidates: 0,
                matched: 0,
                planned: 0,
                skippedManual: 0,
                ambiguous: 0,
                missingProducts: 0,
                updated: 0,
                searchSynced: 0,
            );

            return self::SUCCESS;
        }

        $plans = $this->buildUpdatePlans(
            supplierId: $supplierId,
            candidates: $corrections['candidates'],
        );

        if ($plans['planned'] !== [] && $showSamples > 0) {
            $rows = collect($plans['planned'])
                ->take($showSamples)
                ->map(static fn (array $plan): array => [
                    (string) $plan['product_id'],
                    $plan['external_ids'],
                    $plan['current_name'],
                    $plan['next_name'],
                ])
                ->values()
                ->all();

            $this->newLine();
            $this->table(['product_id', 'external_ids', 'name_from', 'name_to'], $rows);
        }

        $updated = 0;
        $searchSynced = 0;

        if ($write && $plans['planned'] !== []) {
            $updatedIds = [];
            $timestamp = now();

            foreach ($plans['planned'] as $plan) {
                DB::table('products')
                    ->where('id', $plan['product_id'])
                    ->update(array_merge(
                        $plan['updates'],
                        ['updated_at' => $timestamp],
                    ));

                $updatedIds[] = $plan['product_id'];
            }

            $updated = count($updatedIds);
            $searchSynced = (int) ($searchSync->syncIds($updatedIds)['synced'] ?? 0);
        }

        $this->renderSummary(
            scanned: $corrections['scanned'],
            candidates: count($corrections['candidates']),
            matched: $plans['matched'],
            planned: count($plans['planned']),
            skippedManual: $plans['skipped_manual'],
            ambiguous: $plans['ambiguous'],
            missingProducts: $plans['missing_products'],
            updated: $updated,
            searchSynced: $searchSynced,
        );

        return self::SUCCESS;
    }

    private function resolveSourceRecord(): ?SupplierImportSource
    {
        $sourceId = $this->option('source-id');

        if (! is_numeric($sourceId)) {
            return null;
        }

        return SupplierImportSource::query()->find((int) $sourceId);
    }

    private function resolveSupplierId(?SupplierImportSource $sourceRecord): ?int
    {
        $supplierId = $this->option('supplier-id');

        if (is_numeric($supplierId)) {
            return (int) $supplierId;
        }

        if ($sourceRecord instanceof SupplierImportSource) {
            return (int) $sourceRecord->supplier_id;
        }

        return null;
    }

    private function resolveSource(?SupplierImportSource $sourceRecord): ?string
    {
        $explicitSource = trim((string) ($this->option('source') ?? ''));

        if ($explicitSource !== '') {
            return $explicitSource;
        }

        if ($sourceRecord instanceof SupplierImportSource) {
            return $this->sourceFromSettings($sourceRecord);
        }

        $supplierId = $this->option('supplier-id');

        if (! is_numeric($supplierId)) {
            return null;
        }

        $candidates = SupplierImportSource::query()
            ->where('supplier_id', (int) $supplierId)
            ->where('driver_key', 'yandex_market_feed')
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        return $this->sourceFromSettings($candidates->first());
    }

    private function sourceFromSettings(SupplierImportSource $source): ?string
    {
        $settings = is_array($source->settings) ? $source->settings : [];
        $sourceUrl = trim((string) ($settings['source_url'] ?? ''));

        if ($sourceUrl !== '') {
            return $sourceUrl;
        }

        $uploaded = $settings['source_upload'] ?? null;

        if (is_array($uploaded)) {
            $uploaded = $uploaded[0] ?? null;
        }

        if (! is_string($uploaded) || trim($uploaded) === '') {
            return null;
        }

        return Storage::disk('local')->path(trim($uploaded));
    }

    /**
     * @return array{
     *     scanned:int,
     *     candidates: array<string, array{external_id:string,legacy_name:string,resolved_name:string}>
     * }
     */
    private function collectCorrections(
        YmlStreamParser $parser,
        string $path,
        VendorModelOfferNameResolver $nameResolver,
    ): array {
        $limit = max(0, (int) $this->option('limit'));
        $scanned = 0;
        $candidates = [];
        $stream = $parser->open($path);

        foreach ($stream->offers as $record) {
            if (! $record instanceof YmlOfferRecord || $record->type !== 'vendor.model') {
                continue;
            }

            if ($limit > 0 && $scanned >= $limit) {
                break;
            }

            $scanned++;

            $externalId = trim($record->id);

            if ($externalId === '') {
                continue;
            }

            $xml = $this->loadOfferXml($record->xml);

            if (! $xml instanceof SimpleXMLElement) {
                continue;
            }

            $typePrefix = $this->textOrNull($xml->typePrefix ?? null);
            $vendor = $this->textOrNull($xml->vendor ?? null);
            $model = $this->textOrNull($xml->model ?? null);
            $fallbackName = $this->textOrNull($xml->name ?? null);

            $legacyName = $nameResolver->composeLegacyName(
                typePrefix: $typePrefix,
                vendor: $vendor,
                model: $model,
                fallbackName: $fallbackName,
            );
            $resolvedName = $nameResolver->resolveName(
                typePrefix: $typePrefix,
                vendor: $vendor,
                model: $model,
                fallbackName: $fallbackName,
            );

            if ($legacyName === null || $resolvedName === null || $legacyName === $resolvedName) {
                continue;
            }

            $candidates[$externalId] = [
                'external_id' => $externalId,
                'legacy_name' => $legacyName,
                'resolved_name' => $resolvedName,
            ];
        }

        return [
            'scanned' => $scanned,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  array<string, array{external_id:string,legacy_name:string,resolved_name:string}>  $candidates
     * @return array{
     *     matched:int,
     *     planned: array<int, array{
     *         product_id:int,
     *         external_ids:string,
     *         current_name:string,
     *         next_name:string,
     *         updates:array<string, string>
     *     }>,
     *     skipped_manual:int,
     *     ambiguous:int,
     *     missing_products:int
     * }
     */
    private function buildUpdatePlans(int $supplierId, array $candidates): array
    {
        $matched = 0;
        $missingProducts = 0;
        $groupedByProduct = [];

        foreach (array_chunk(array_keys($candidates), 500) as $chunk) {
            $rows = DB::table('product_supplier_references as refs')
                ->leftJoin('products', 'products.id', '=', 'refs.product_id')
                ->select([
                    'refs.external_id',
                    'refs.product_id',
                    'products.id as existing_product_id',
                    'products.name',
                    'products.title',
                    'products.meta_title',
                ])
                ->where('refs.supplier_id', $supplierId)
                ->whereIn('refs.external_id', $chunk)
                ->get();

            foreach ($rows as $row) {
                $matched++;

                if (! is_numeric($row->existing_product_id)) {
                    $missingProducts++;

                    continue;
                }

                $proposal = $candidates[(string) $row->external_id];
                $groupedByProduct[(int) $row->existing_product_id][] = [
                    'external_id' => $proposal['external_id'],
                    'legacy_name' => $proposal['legacy_name'],
                    'resolved_name' => $proposal['resolved_name'],
                    'current_name' => (string) $row->name,
                    'current_title' => is_string($row->title) ? $row->title : null,
                    'current_meta_title' => is_string($row->meta_title) ? $row->meta_title : null,
                ];
            }
        }

        $planned = [];
        $skippedManual = 0;
        $ambiguous = 0;

        foreach ($groupedByProduct as $productId => $proposals) {
            $legacyNames = collect($proposals)->pluck('legacy_name')->unique()->values();
            $resolvedNames = collect($proposals)->pluck('resolved_name')->unique()->values();

            if ($legacyNames->count() !== 1 || $resolvedNames->count() !== 1) {
                $ambiguous++;

                continue;
            }

            $currentName = (string) ($proposals[0]['current_name'] ?? '');
            $currentTitle = $proposals[0]['current_title'] ?? null;
            $currentMetaTitle = $proposals[0]['current_meta_title'] ?? null;
            $legacyName = (string) $legacyNames->first();
            $resolvedName = (string) $resolvedNames->first();

            $nameMatchesLegacy = $currentName === $legacyName;
            $nameMatchesResolved = $currentName === $resolvedName;

            if (! $nameMatchesLegacy && ! $nameMatchesResolved) {
                $skippedManual++;

                continue;
            }

            $updates = [];

            if ($nameMatchesLegacy) {
                $updates['name'] = $resolvedName;
                $updates['name_normalized'] = NameNormalizer::normalize($resolvedName);
            }

            if ($currentMetaTitle === $legacyName) {
                $updates['meta_title'] = $resolvedName;
            }

            if ($currentTitle === $legacyName) {
                $updates['title'] = $resolvedName;
            }

            if ($updates === []) {
                continue;
            }

            $planned[$productId] = [
                'product_id' => $productId,
                'external_ids' => collect($proposals)
                    ->pluck('external_id')
                    ->unique()
                    ->implode(','),
                'current_name' => $currentName,
                'next_name' => $updates['name'] ?? $currentName,
                'updates' => $updates,
            ];
        }

        return [
            'matched' => $matched,
            'planned' => $planned,
            'skipped_manual' => $skippedManual,
            'ambiguous' => $ambiguous,
            'missing_products' => $missingProducts,
        ];
    }

    private function renderSummary(
        int $scanned,
        int $candidates,
        int $matched,
        int $planned,
        int $skippedManual,
        int $ambiguous,
        int $missingProducts,
        int $updated,
        int $searchSynced,
    ): void {
        $this->newLine();
        $this->line(
            'Summary: scanned='.$scanned
            .', candidates='.$candidates
            .', matched='.$matched
            .', planned='.$planned
            .', skipped_manual='.$skippedManual
            .', ambiguous='.$ambiguous
            .', missing_products='.$missingProducts
            .', updated='.$updated
            .', search_synced='.$searchSynced
        );
    }

    private function loadOfferXml(string $xml): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $node = simplexml_load_string(trim($xml));

            return $node instanceof SimpleXMLElement ? $node : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
