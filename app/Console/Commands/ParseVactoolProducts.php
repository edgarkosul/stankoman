<?php

namespace App\Console\Commands;

use App\Support\Vactool\VactoolProductImportService;
use Illuminate\Console\Command;

class ParseVactoolProducts extends Command
{
    protected $signature = 'products:parse-vactool
                            {--sitemap=https://vactool.ru/sitemap.xml : Sitemap URL}
                            {--match=/catalog/product- : URL fragment used for product pages}
                            {--limit=0 : Max product URLs to process (0 = all)}
                            {--delay-ms=250 : Delay between product requests in milliseconds}
                            {--write : Save parsed products into the local DB}
                            {--publish : Set imported products as active}
                            {--download-images : Download image URLs into storage/app/public/pics and use local paths}
                            {--skip-existing : Skip existing products by supplier external_id}
                            {--mode=partial_import : Import mode: partial_import or full_sync_authoritative}
                            {--full-sync : Shortcut for mode=full_sync_authoritative}
                            {--finalize-missing=1 : In full_sync_authoritative, deactivate products missing in source}
                            {--create-missing=1 : Create products that are absent in local DB}
                            {--update-existing=1 : Update products that already exist in local DB}
                            {--show-samples=3 : Max number of sample rows in dry-run mode}';

    protected $description = 'Parse product pages from vactool sitemap';

    public function __construct(private VactoolProductImportService $importService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = $this->resolveMode();

        $result = $this->importService->run([
            'sitemap' => (string) $this->option('sitemap'),
            'match' => (string) $this->option('match'),
            'limit' => max(0, (int) $this->option('limit')),
            'delay_ms' => max(0, (int) $this->option('delay-ms')),
            'write' => (bool) $this->option('write'),
            'publish' => (bool) $this->option('publish'),
            'download_images' => (bool) $this->option('download-images'),
            'skip_existing' => (bool) $this->option('skip-existing'),
            'mode' => $mode,
            'finalize_missing' => $this->resolveBooleanOption(
                name: 'finalize-missing',
                default: $mode === 'full_sync_authoritative',
            ),
            'create_missing' => $this->resolveBooleanOption('create-missing', true),
            'update_existing' => $this->resolveBooleanOption('update-existing', true),
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
            $this->error('Не удалось прочитать sitemap: '.$result['fatal_error']);

            return self::FAILURE;
        }

        if ($result['no_urls']) {
            return self::SUCCESS;
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
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
}
