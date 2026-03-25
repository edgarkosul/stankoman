<?php

namespace App\Console\Commands;

use App\Support\Products\ProductSearchSync;
use Illuminate\Console\Command;
use Throwable;

class ProductSearchReindexCommand extends Command
{
    protected $signature = 'products:search-reindex
        {--chunk=500 : Number of products per sync chunk}
        {--skip-settings : Skip scout:sync-index-settings before rebuild}';

    protected $description = 'Rebuild the Product Scout index synchronously for nightly reconciliation';

    /**
     * Execute the console command.
     */
    public function handle(ProductSearchSync $searchSync): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        try {
            if (! (bool) $this->option('skip-settings')) {
                $this->line('Syncing Scout index settings...');

                $exitCode = $this->call('scout:sync-index-settings');

                if ($exitCode !== self::SUCCESS) {
                    $this->error('Failed to sync Scout index settings.');

                    return self::FAILURE;
                }
            }

            $this->line("Rebuilding product search index with chunk size {$chunk}...");

            $result = $searchSync->rebuildIndex($chunk);

            $this->info('Product search reindex completed. Indexed: '.$result['indexed'].'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Product search reindex failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
