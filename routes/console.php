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

/*
 * Сверка каталога с поисковым индексом (Meilisearch, индекс stankoman_products).
 *
 * Раньше здесь в 06:30 стоял products:search-reindex — полная пересборка,
 * которая начинается с removeAllFromSearch(): всё время сборки поиск на сайте
 * отдавал пустоту, и это уже утренний трафик. Плюс она чинила молча, а значит
 * не давала узнать, что появилось новое место, пишущее мимо индекса.
 *
 * search:audit сначала докладывает расхождение в лог и только потом чинит —
 * полным проходом (он же переписывает документы, разошедшиеся по содержимому:
 * по множествам id такое не видно) плюс удалением лишних документов.
 * Живые места зовут ProductSearchSync сразу, это сеть под ними.
 *
 * Настройки индекса синхронизируем перед сверкой: новые фильтруемые поля
 * иначе доедут до Meilisearch только руками. Команда идемпотентна.
 *
 * products:search-reindex осталась ручной командой — на случай, когда индекс
 * надо собрать с нуля.
 */
Schedule::command('scout:sync-index-settings')
    ->dailyAt('04:40')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/search-audit.log'));

Schedule::command('search:audit', ['--fix'])
    ->dailyAt('04:45')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/search-audit.log'));

/*
 * База знаний ИИ-ассистента — страховочный ночной проход.
 *
 * Правки страниц и статей доезжают до индекса сразу, наблюдателями через очередь.
 * Ночью ловится то, что мимо них: реквизиты из настроек (воркер держит конфиг
 * с момента запуска и новых значений не видит), потерянные задачи очереди,
 * страница, убранная из белого списка (--prune).
 *
 * Проход инкрементный по content_hash: неизменившийся текст не пересчитывается
 * и не оплачивается, так что ночь без правок стоит ноль вызовов шлюза.
 */
Schedule::command('ai:kb-reindex', ['--prune'])
    ->dailyAt('04:00')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/ai-kb-reindex.log'));

Schedule::command('legacy:kraton-match')
    ->dailyAt('05:20')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/legacy-kraton-match.log'));

Schedule::command('products:sync-currency-rates')
    ->dailyAt('00:00')
    ->withoutOverlapping(180);

Schedule::command('seo:generate-sitemap')
    ->dailyAt('04:30')
    ->withoutOverlapping(180)
    ->evenInMaintenanceMode()
    ->appendOutputTo(storage_path('logs/seo-sitemap.log'));

Schedule::command('feeds:generate-market')
    ->dailyAt('04:40')
    ->withoutOverlapping(180)
    ->evenInMaintenanceMode()
    ->appendOutputTo(storage_path('logs/market-feed.log'));

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
