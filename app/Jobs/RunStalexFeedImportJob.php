<?php

namespace App\Jobs;

use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Suppliers\Stalex\StalexFeedImportService;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;

/**
 * Прогон импорта фида Stalex.
 *
 * Вся логика прогона — из {@see RunYandexMarketFeedImportJob}; здесь лишь
 * подменяется инжектируемый сервис на {@see StalexFeedImportService}
 * (со Stalex-адаптером: галерея + чистка описания) и отдельный ключ
 * блокировки, чтобы Stalex-прогон не мешал штатному Yandex-импорту.
 */
final class RunStalexFeedImportJob extends RunYandexMarketFeedImportJob
{
    protected function overlappingKey(): string
    {
        return 'catalog_import_stalex';
    }

    public function handle(YandexMarketFeedImportService $service, ImportRunOrchestrator $runs): void
    {
        $this->runImport(app(StalexFeedImportService::class), $runs);
    }
}
