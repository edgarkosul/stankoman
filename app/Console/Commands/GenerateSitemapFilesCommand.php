<?php

namespace App\Console\Commands;

use App\Support\Seo\SitemapGenerator;
use Illuminate\Console\Command;

class GenerateSitemapFilesCommand extends Command
{
    protected $signature = 'seo:generate-sitemap';

    protected $description = 'Generate robots.txt and sitemap XML files.';

    public function __construct(private SitemapGenerator $generator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->generator->generate();

        $this->info('Sitemap index: '.$result['index']);
        $this->info('Static sitemap: '.$result['static']);
        $this->info('Category sitemap: '.$result['categories']);
        $this->info('Product sitemap files: '.$result['product_sitemaps']);
        $this->info('Product URLs exported: '.$result['product_urls']);
        $this->info('Robots file: '.$result['robots']);

        return self::SUCCESS;
    }
}
