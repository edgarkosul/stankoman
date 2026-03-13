<?php

namespace App\Console\Commands;

use App\Jobs\RunMetalmasterProductImportJob;
use App\Jobs\RunVactoolProductImportJob;
use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierProfile;
use App\Support\CatalogImport\Suppliers\Vactool\VactoolSupplierProfile;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use App\Support\CatalogImport\Yml\YandexMarketFeedProfile;
use App\Support\Metalmaster\MetalmasterProductImportService;
use App\Support\Vactool\VactoolProductImportService;
use Illuminate\Console\Command;

class CatalogImportProductsCommand extends Command
{
    protected $signature = 'catalog:import-products
        {supplier : Supplier key: vactool, metalmaster, or yandex_market_feed}
        {--profile= : Supplier profile key}
        {--source= : Generic source (URL/file path)}
        {--supplier-id= : Global supplier id}
        {--sitemap= : Sitemap URL for vactool}
        {--buckets-file= : Buckets file for metalmaster}
        {--feed= : Yandex Market feed URL or file path}
        {--bucket= : Category bucket for metalmaster}
        {--match=/catalog/product- : URL match fragment for vactool}
        {--limit=0 : Maximum records to process}
        {--timeout=25 : HTTP timeout in seconds (metalmaster/yandex)}
        {--delay-ms=250 : Delay between source requests}
        {--write : Persist data to DB (otherwise dry-run)}
        {--queue : Dispatch queued job instead of foreground execution}
        {--publish : Set imported products as active}
        {--download-images : Queue media download for images}
        {--force-media-recheck : Force media recheck at donor even when URL is already known}
        {--skip-existing : Skip products already known by supplier + external_id}
        {--mode=partial_import : partial_import or full_sync_authoritative}
        {--full-sync : Shortcut for mode=full_sync_authoritative}
        {--finalize-missing=1 : Deactivate missing products in full sync mode}
        {--create-missing=1 : Create products that are missing locally}
        {--update-existing=1 : Update products that already exist locally}
        {--show-samples=3 : Dry-run sample rows}
        {--error-threshold-count= : Stop run when error count reaches threshold}
        {--error-threshold-percent= : Stop run when error percent reaches threshold}';

    protected $description = 'Run supplier import by a unified command (vactool/metalmaster/yandex)';

    public function handle(ImportRunOrchestrator $runs): int
    {
        $supplier = $this->resolveSupplier();

        if ($supplier === null) {
            $this->error('Unknown supplier. Allowed values: vactool, metalmaster, yandex_market_feed.');

            return self::INVALID;
        }

        $profile = $this->resolveProfile($supplier);

        if ($profile === null) {
            $this->error('Unsupported profile for supplier '.$supplier.'.');

            return self::INVALID;
        }

        $write = (bool) $this->option('write');
        $mode = $this->resolveMode();
        $options = $this->buildSupplierOptions($supplier, $profile, $mode, $write);
        $runType = $this->runType($supplier);
        $run = $runs->start(
            type: $runType,
            columns: $options,
            mode: $write ? 'write' : 'dry-run',
            sourceFilename: (string) ($options['sitemap'] ?? $options['buckets_file'] ?? $options['source'] ?? null),
            userId: null,
            meta: [
                'supplier' => $supplier,
                'profile' => $profile,
            ],
        );

        $this->info('Run created: #'.$run->id.' ('.$runType.').');

        if ((bool) $this->option('queue')) {
            $this->dispatchQueuedJob($supplier, $run, $options, $write);
            $this->line('Queued successfully. Use import history to monitor progress.');

            return self::SUCCESS;
        }

        $this->line('Running in foreground...');
        $this->runSynchronously($supplier, $run, $options, $write, $runs);
        $run->refresh();

        $this->renderRunSummary($run);

        return in_array((string) $run->status, ['completed', 'applied', 'dry_run'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function resolveSupplier(): ?string
    {
        $supplier = mb_strtolower(trim((string) $this->argument('supplier')));

        if ($supplier === 'yandex') {
            return 'yandex_market_feed';
        }

        return in_array($supplier, ['vactool', 'metalmaster', 'yandex_market_feed'], true)
            ? $supplier
            : null;
    }

    private function resolveProfile(string $supplier): ?string
    {
        $provided = trim((string) ($this->option('profile') ?? ''));
        $default = match ($supplier) {
            'vactool' => app(VactoolSupplierProfile::class)->profileKey(),
            'metalmaster' => app(MetalmasterSupplierProfile::class)->profileKey(),
            'yandex_market_feed' => app(YandexMarketFeedProfile::class)->profileKey(),
            default => null,
        };

        if ($default === null) {
            return null;
        }

        if ($provided === '' || $provided === 'default') {
            return $default;
        }

        return $provided === $default ? $default : null;
    }

    private function runType(string $supplier): string
    {
        return match ($supplier) {
            'vactool' => 'vactool_products',
            'metalmaster' => 'metalmaster_products',
            'yandex_market_feed' => 'yandex_market_feed_products',
            default => 'products',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSupplierOptions(string $supplier, string $profile, string $mode, bool $write): array
    {
        $baseOptions = [
            'write' => $write,
            'limit' => max(0, (int) $this->option('limit')),
            'delay_ms' => max(0, (int) $this->option('delay-ms')),
            'publish' => (bool) $this->option('publish'),
            'download_images' => (bool) $this->option('download-images'),
            'force_media_recheck' => (bool) $this->option('force-media-recheck'),
            'skip_existing' => (bool) $this->option('skip-existing'),
            'show_samples' => max(0, (int) $this->option('show-samples')),
            'mode' => $mode,
            'finalize_missing' => $this->resolveBooleanOption(
                name: 'finalize-missing',
                default: $mode === 'full_sync_authoritative',
            ),
            'create_missing' => $this->resolveBooleanOption('create-missing', true),
            'update_existing' => $this->resolveBooleanOption('update-existing', true),
            'error_threshold_count' => $this->nullableIntegerOption('error-threshold-count'),
            'error_threshold_percent' => $this->nullableFloatOption('error-threshold-percent'),
            'supplier_id' => $this->nullableIntegerOption('supplier-id'),
            'supplier' => $supplier,
            'profile' => $profile,
        ];

        if ($supplier === 'vactool') {
            $source = trim((string) (
                $this->option('source')
                ?? $this->option('sitemap')
                ?? 'https://vactool.ru/sitemap.xml'
            ));

            return array_merge($baseOptions, [
                'sitemap' => $source !== '' ? $source : 'https://vactool.ru/sitemap.xml',
                'match' => (string) ($this->option('match') ?? '/catalog/product-'),
            ]);
        }

        $source = trim((string) (
            $this->option('source')
            ?? ($supplier === 'metalmaster'
                ? $this->option('buckets-file')
                : $this->option('feed'))
            ?? ($supplier === 'metalmaster'
                ? storage_path('app/parser/metalmaster-buckets.json')
                : storage_path('app/parser/yandex-market-feed.xml'))
        ));

        if ($supplier === 'metalmaster') {
            return array_merge($baseOptions, [
                'buckets_file' => $source !== '' ? $source : storage_path('app/parser/metalmaster-buckets.json'),
                'bucket' => (string) ($this->option('bucket') ?? ''),
                'timeout' => max(1, (int) $this->option('timeout')),
            ]);
        }

        return array_merge($baseOptions, [
            'source' => $source !== '' ? $source : storage_path('app/parser/yandex-market-feed.xml'),
            'timeout' => max(1, (int) $this->option('timeout')),
        ]);
    }

    private function dispatchQueuedJob(string $supplier, ImportRun $run, array $options, bool $write): void
    {
        if ($supplier === 'vactool') {
            RunVactoolProductImportJob::dispatch($run->id, $options, $write)->afterCommit();

            return;
        }

        if ($supplier === 'metalmaster') {
            RunMetalmasterProductImportJob::dispatch($run->id, $options, $write)->afterCommit();

            return;
        }

        RunYandexMarketFeedImportJob::dispatch($run->id, $options, $write)->afterCommit();
    }

    private function runSynchronously(
        string $supplier,
        ImportRun $run,
        array $options,
        bool $write,
        ImportRunOrchestrator $runs,
    ): void {
        if ($supplier === 'vactool') {
            (new RunVactoolProductImportJob($run->id, $options, $write))
                ->handle(app(VactoolProductImportService::class), $runs);

            return;
        }

        if ($supplier === 'metalmaster') {
            (new RunMetalmasterProductImportJob($run->id, $options, $write))
                ->handle(app(MetalmasterProductImportService::class), $runs);

            return;
        }

        (new RunYandexMarketFeedImportJob($run->id, $options, $write))
            ->handle(app(YandexMarketFeedImportService::class), $runs);
    }

    private function renderRunSummary(ImportRun $run): void
    {
        $totals = is_array($run->totals) ? $run->totals : [];

        $this->newLine();
        $this->info('Run #'.$run->id.' finished with status: '.((string) $run->status));
        $this->line(
            'Summary: processed='.(int) ($totals['scanned'] ?? 0)
            .', created='.(int) ($totals['create'] ?? 0)
            .', updated='.(int) ($totals['update'] ?? 0)
            .', skipped='.(int) ($totals['same'] ?? 0)
            .', errors='.(int) ($totals['error'] ?? 0)
        );
    }

    private function resolveMode(): string
    {
        if ((bool) $this->option('full-sync')) {
            return 'full_sync_authoritative';
        }

        $mode = trim((string) ($this->option('mode') ?? 'partial_import'));

        return in_array($mode, ['partial_import', 'full_sync_authoritative'], true)
            ? $mode
            : 'partial_import';
    }

    private function resolveBooleanOption(string $name, bool $default): bool
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $value = mb_strtolower(trim($value));

            if (in_array($value, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (in_array($value, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    private function nullableIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloatOption(string $name): ?float
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
