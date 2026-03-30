<?php

namespace App\Console\Commands;

use App\Support\Feeds\YandexMarketFeedGenerator;
use Illuminate\Console\Command;

class GenerateMarketFeedCommand extends Command
{
    protected $signature = 'feeds:generate-market';

    protected $description = 'Generate the Yandex Market XML feed.';

    public function __construct(private YandexMarketFeedGenerator $generator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->generator->generate();

        $this->info('Market feed: '.$result['path']);
        $this->info('Categories exported: '.$result['categories']);
        $this->info('Offers exported: '.$result['offers']);

        return self::SUCCESS;
    }
}
