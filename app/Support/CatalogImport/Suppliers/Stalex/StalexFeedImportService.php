<?php

namespace App\Support\CatalogImport\Suppliers\Stalex;

use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\Processing\ProductImportProcessor;
use App\Support\CatalogImport\Sources\SourceResolver;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use App\Support\CatalogImport\Yml\YmlStreamParser;

/**
 * Импорт-сервис Stalex.
 *
 * Оркестрация (скан фида, дедуп/скачивание картинок, upsert товаров,
 * прогресс, логирование прогонов) полностью переиспуется из
 * {@see YandexMarketFeedImportService} — подменяются только адаптер
 * (галерея + чистка описания) и профиль (supplierKey=stalex, под которым
 * пишутся ссылки поставщика, кэш-ключ и события прогона).
 */
final class StalexFeedImportService extends YandexMarketFeedImportService
{
    public function __construct(
        StalexSupplierAdapter $adapter,
        StalexSupplierProfile $profile,
        YmlStreamParser $recordParser,
        ProductImportProcessor $processor,
        SourceResolverInterface $sourceResolver = new SourceResolver,
    ) {
        parent::__construct($adapter, $profile, $recordParser, $processor, $sourceResolver);
    }
}
