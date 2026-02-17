<?php

namespace App\Console\Commands;

use App\Support\Metalmaster\MetalmasterSitemapBucketService;
use Illuminate\Console\Command;
use Throwable;

class SitemapBucketsCommand extends Command
{
    protected $signature = 'parser:sitemap-buckets
        {--sitemap=https://metalmaster.ru/sitemap.xml}
        {--exclude-news=1}
        {--output-file= : Absolute path for latest buckets file}
        {--snapshot-dir= : Absolute dir for versioned buckets snapshots}
        {--with-snapshot=1 : Save versioned snapshot alongside latest file}';

    protected $description = 'Build category buckets from sitemap';

    public function __construct(private MetalmasterSitemapBucketService $bucketService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->bucketService->build([
                'sitemap' => (string) $this->option('sitemap'),
                'exclude_news' => (bool) ((int) $this->option('exclude-news')),
                'output_file' => (string) ($this->option('output-file') ?: storage_path('app/parser/metalmaster-buckets.json')),
                'snapshot_dir' => (string) ($this->option('snapshot-dir') ?: storage_path('app/parser/metalmaster')),
                'with_snapshot' => (bool) ((int) $this->option('with-snapshot')),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Buckets saved: '.$result['buckets_count']);
        $this->info('Products total: '.$result['products_count']);
        $this->info('Scanned URLs: '.$result['scanned_urls']);
        $this->info('File: '.$result['latest_file']);
        $this->info('Meta: '.$result['meta_file']);

        if (is_string($result['snapshot_file']) && $result['snapshot_file'] !== '') {
            $this->info('Snapshot: '.$result['snapshot_file']);
        }

        return self::SUCCESS;
    }
}
