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

$catalogImportSchedule = config('catalog-import.schedule', []);
$catalogScheduleEnabled = (bool) ($catalogImportSchedule['enabled'] ?? false);
$catalogScheduleTimezone = (string) ($catalogImportSchedule['timezone'] ?? 'Europe/Moscow');

if ($catalogScheduleEnabled) {
    $vactoolSchedule = is_array($catalogImportSchedule['vactool'] ?? null) ? $catalogImportSchedule['vactool'] : [];

    if ((bool) ($vactoolSchedule['enabled'] ?? false)) {
        $vactoolTime = (string) ($vactoolSchedule['time'] ?? '04:00');
        $vactoolMode = (string) ($vactoolSchedule['mode'] ?? 'partial_import');
        $vactoolSource = (string) ($vactoolSchedule['source'] ?? 'https://vactool.ru/sitemap.xml');
        $vactoolDownloadImages = (bool) ($vactoolSchedule['download_images'] ?? true);
        $vactoolSkipExisting = (bool) ($vactoolSchedule['skip_existing'] ?? false);

        $vactoolCommand = sprintf(
            'catalog:import-products vactool --queue --write --mode=%s --source=%s --download-images=%d --skip-existing=%d',
            escapeshellarg($vactoolMode),
            escapeshellarg($vactoolSource),
            $vactoolDownloadImages ? 1 : 0,
            $vactoolSkipExisting ? 1 : 0,
        );

        Schedule::command($vactoolCommand)
            ->dailyAt($vactoolTime)
            ->timezone($catalogScheduleTimezone)
            ->withoutOverlapping(180);
    }

    $metalmasterSchedule = is_array($catalogImportSchedule['metalmaster'] ?? null) ? $catalogImportSchedule['metalmaster'] : [];

    if ((bool) ($metalmasterSchedule['enabled'] ?? false)) {
        $metalmasterTime = (string) ($metalmasterSchedule['time'] ?? '04:30');
        $metalmasterMode = (string) ($metalmasterSchedule['mode'] ?? 'partial_import');
        $metalmasterSource = (string) ($metalmasterSchedule['source'] ?? storage_path('app/parser/metalmaster-buckets.json'));
        $metalmasterBucket = (string) ($metalmasterSchedule['bucket'] ?? '');
        $metalmasterTimeout = max(1, (int) ($metalmasterSchedule['timeout'] ?? 25));
        $metalmasterDownloadImages = (bool) ($metalmasterSchedule['download_images'] ?? true);
        $metalmasterSkipExisting = (bool) ($metalmasterSchedule['skip_existing'] ?? false);

        $metalmasterCommand = sprintf(
            'catalog:import-products metalmaster --queue --write --mode=%s --source=%s --bucket=%s --timeout=%d --download-images=%d --skip-existing=%d',
            escapeshellarg($metalmasterMode),
            escapeshellarg($metalmasterSource),
            escapeshellarg($metalmasterBucket),
            $metalmasterTimeout,
            $metalmasterDownloadImages ? 1 : 0,
            $metalmasterSkipExisting ? 1 : 0,
        );

        Schedule::command($metalmasterCommand)
            ->dailyAt($metalmasterTime)
            ->timezone($catalogScheduleTimezone)
            ->withoutOverlapping(180);
    }
}
