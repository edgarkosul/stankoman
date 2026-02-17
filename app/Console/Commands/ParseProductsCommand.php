<?php

namespace App\Console\Commands;

use App\Support\Metalmaster\MetalmasterProductImportService;
use Illuminate\Console\Command;

class ParseProductsCommand extends Command
{
    protected $signature = 'parser:parse-products
        {--bucket= : Category bucket, e.g. promyshlennye}
        {--limit=0 : Max product URLs to parse (0 = all)}
        {--buckets-file= : Absolute path to buckets json}
        {--timeout=25 : Request timeout in seconds}
        {--delay-ms= : Delay between requests in ms}
        {--sleep=250 : Legacy alias for delay-ms}
        {--dry-run=0 : Legacy mode flag (1 = do not write DB)}
        {--write : Save parsed products into DB}
        {--publish : Set imported products as active}
        {--skip-existing : Skip existing products by slug}
        {--show-samples=3 : Max number of sample rows in dry-run mode}';

    protected $description = 'Parse product pages from metalmaster buckets';

    public function __construct(private MetalmasterProductImportService $importService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $legacyDryRun = (bool) ((int) $this->option('dry-run'));
        $explicitWrite = (bool) $this->option('write');

        $write = $explicitWrite || ! $legacyDryRun;

        $result = $this->importService->run([
            'buckets_file' => (string) ($this->option('buckets-file') ?: storage_path('app/parser/metalmaster-buckets.json')),
            'bucket' => (string) $this->option('bucket'),
            'limit' => max(0, (int) $this->option('limit')),
            'timeout' => max(1, (int) $this->option('timeout')),
            'delay_ms' => $this->resolveDelayMs(),
            'write' => $write,
            'publish' => (bool) $this->option('publish'),
            'skip_existing' => (bool) $this->option('skip-existing'),
            'show_samples' => max(0, (int) $this->option('show-samples')),
        ], function (string $type, string|array $payload): void {
            if ($type === 'table' && is_array($payload)) {
                $headers = $payload['headers'] ?? [];
                $rows = $payload['rows'] ?? [];
                $this->table($headers, $rows);

                return;
            }

            if (! is_string($payload)) {
                return;
            }

            if ($type === 'info') {
                $this->info($payload);

                return;
            }

            if ($type === 'warn') {
                $this->warn($payload);

                return;
            }

            if ($type === 'error') {
                $this->error($payload);

                return;
            }

            if ($type === 'new_line') {
                $this->newLine();

                return;
            }

            $this->line($payload);
        });

        if ($result['fatal_error'] !== null) {
            $this->error($result['fatal_error']);
            $this->line('Run: php artisan parser:sitemap-buckets --sitemap=https://metalmaster.ru/sitemap.xml');

            return self::FAILURE;
        }

        if ($result['no_urls']) {
            return self::SUCCESS;
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveDelayMs(): int
    {
        $delayMs = $this->option('delay-ms');

        if ($delayMs !== null && $delayMs !== '') {
            return max(0, (int) $delayMs);
        }

        return max(0, (int) $this->option('sleep'));
    }
}
