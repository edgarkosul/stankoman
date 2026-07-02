<?php

namespace App\Support\CatalogImport\Drivers;

use App\Jobs\RunStalexFeedImportJob;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Suppliers\Stalex\StalexSupplierProfile;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use App\Support\CatalogImport\Yml\YandexMarketFeedSourceHistoryService;
use RuntimeException;

/**
 * Драйвер импорта фида Stalex.
 *
 * Фид Stalex — обычный Yandex Market YML, поэтому вся UI-обвязка
 * (источник/загрузка/история, загрузка категорий, runtime-схема) и оркестрация
 * переиспуются из {@see YandexMarketFeedDriver}. Отличия:
 *
 *  - привязан к поставщику Stalex (slug=stalex), доступен только для него;
 *  - дефолтный источник — «рабочий» фид с фотогалереей;
 *  - импорт уходит в {@see RunStalexFeedImportJob} (Stalex-адаптер: галерея
 *    из <param name="Фотогалерея"> + чистка #BLOCK...# в описании);
 *  - деактивация не поддерживается (в рамках задачи по картинкам).
 *
 * Наследование от YandexMarketFeedDriver также заставляет Filament-страницу
 * SupplierImport считать драйвер «yandex-подобным» (instanceof-проверки),
 * благодаря чему UI загрузки категорий работает без нового кода.
 *
 * @see docs/stalex_driver.md
 */
final class StalexYmlDriver extends YandexMarketFeedDriver
{
    public function __construct(
        private readonly StalexSupplierProfile $stalexProfile,
        YandexMarketFeedImportService $service,
        private readonly YandexMarketFeedSourceHistoryService $stalexHistory,
    ) {
        parent::__construct($stalexProfile, $service, $stalexHistory);
    }

    public function key(): string
    {
        return $this->stalexProfile->supplierKey();
    }

    public function label(): string
    {
        return 'Stalex YML (с фотогалереей)';
    }

    public function availability(): DriverAvailability
    {
        return DriverAvailability::SupplierSpecific;
    }

    public function isAvailableForSupplier(?Supplier $supplier): bool
    {
        return trim((string) $supplier?->slug) === $this->stalexProfile->supplierKey();
    }

    public function defaultSourceName(): string
    {
        return 'Фид Stalex (с фотогалереей)';
    }

    public function defaultSettings(): array
    {
        return array_merge(parent::defaultSettings(), [
            'source_url' => $this->stalexProfile->defaultSourceUrl(),
        ]);
    }

    public function supportsDeactivation(): bool
    {
        return false;
    }

    public function importRunType(): string
    {
        return 'stalex_yml_products';
    }

    public function deactivationRunType(): ?string
    {
        return null;
    }

    public function dispatchImport(ImportRun $run, array $options, bool $write): void
    {
        $sourceId = $options['source_id'] ?? null;

        if (is_numeric($sourceId) && (int) $sourceId > 0) {
            $this->stalexHistory->markUsedById((int) $sourceId, $run->id);
        }

        RunStalexFeedImportJob::dispatch($run->id, $options, $write)->afterCommit();
    }

    public function dispatchDeactivation(ImportRun $run, array $options, bool $write): void
    {
        throw new RuntimeException('Stalex driver does not support deactivation.');
    }

    public function buildDeactivationOptions(SupplierImportSource $source, array $runtime): array
    {
        throw new RuntimeException('Stalex driver does not support deactivation.');
    }
}
