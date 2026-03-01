<?php

namespace App\Console\Commands;

use App\Support\CartService;
use Illuminate\Console\Command;

class CartsCleanupCommand extends Command
{
    protected $signature = 'carts:cleanup {days=30 : Delete guest carts older than N days}';

    protected $description = 'Delete stale guest carts (user_id is null) older than N days';

    public function handle(): int
    {
        $days = (int) $this->argument('days');

        CartService::cleanup($days);

        $this->info("Guest carts older than {$days} days deleted.");

        return self::SUCCESS;
    }
}
