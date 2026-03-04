<?php

use App\Console\Commands\CartsCleanupCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CartsCleanupCommand::class, ['30'])->dailyAt('03:30');

Schedule::command('images:webp-backfill', ['--limit' => 500])
    ->dailyAt('03:30')
    ->withoutOverlapping();
Schedule::command('restic-backups:run --trigger=schedule')->dailyAt('02:00');
Schedule::command('restic-backups:cleanup-exports --hours=24')->daily();
Schedule::command('restic-backups:cleanup-rollbacks --hours=24')->daily();
