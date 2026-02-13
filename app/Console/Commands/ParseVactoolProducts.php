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
                            {--skip-existing : Skip existing products by local key (name + brand)}
                            {--show-samples=3 : Max number of sample rows in dry-run mode}';

    protected $description = 'Parse product pages from vactool sitemap';

    public function __construct(private VactoolProductImportService $importService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->importService->run([
            'sitemap' => (string) $this->option('sitemap'),
            'match' => (string) $this->option('match'),
            'limit' => max(0, (int) $this->option('limit')),
            'delay_ms' => max(0, (int) $this->option('delay-ms')),
            'write' => (bool) $this->option('write'),
            'publish' => (bool) $this->option('publish'),
            'download_images' => (bool) $this->option('download-images'),
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
            $this->error('Не удалось прочитать sitemap: '.$result['fatal_error']);

            return self::FAILURE;
        }

        if ($result['no_urls']) {
            return self::SUCCESS;
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
