<?php

namespace App\Support\CatalogImport\Enums;

/**
 * Типы прогонов импорта (ImportRun::$type).
 *
 * Единый источник истины для человекочитаемых меток, цветов бейджей и того,
 * какие типы показываются в карточке сводки на странице импорта поставщиков.
 * Раньше эти сведения дублировались в нескольких match-выражениях
 * (SupplierImport, ImportRunObserver, ImportRunsTable) и успели разойтись.
 *
 * ImportRun::$type — свободная строка в БД, поэтому для отображения всегда
 * используем безопасные {@see self::labelFor()} / {@see self::colorFor()},
 * которые корректно переваривают неизвестные (например, legacy) значения.
 */
enum ImportRunType: string
{
    case ExcelProducts = 'products';
    case CategoryFilters = 'category_filters';
    case Vactool = 'vactool_products';
    case Metalmaster = 'metalmaster_products';
    case Metaltec = 'metaltec_products';
    case YandexFeed = 'yandex_market_feed_products';
    case YandexDeactivation = 'yandex_market_feed_deactivation';
    case Stalex = 'stalex_yml_products';
    case SpecsMatch = 'specs_match';

    public function label(): string
    {
        return match ($this) {
            self::ExcelProducts => 'Excel товары',
            self::CategoryFilters => 'Категорийные фильтры',
            self::Vactool => 'Vactool',
            self::Metalmaster => 'Metalmaster',
            self::Metaltec => 'Metaltec',
            self::YandexFeed => 'Yandex Market Feed',
            self::YandexDeactivation => 'Деактивация Yandex Feed',
            self::Stalex => 'Stalex',
            self::SpecsMatch => 'Specs match',
        };
    }

    /**
     * Цвет бейджа Filament для колонки «Тип».
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::ExcelProducts => 'gray',
            self::CategoryFilters, self::YandexFeed, self::YandexDeactivation => 'warning',
            self::Vactool => 'primary',
            self::Metalmaster, self::Metaltec, self::Stalex => 'success',
            self::SpecsMatch => 'info',
        };
    }

    /**
     * Показывать ли прогон этого типа в карточке сводки на странице
     * «Импорт поставщиков» (supplier-import). Excel-товары, категорийные
     * фильтры и specs-match генерятся вне этого экрана и в карточку не идут.
     */
    public function inSupplierImportSummary(): bool
    {
        return match ($this) {
            self::Vactool,
            self::Metalmaster,
            self::Metaltec,
            self::YandexFeed,
            self::YandexDeactivation,
            self::Stalex => true,
            default => false,
        };
    }

    /**
     * Безопасная метка для произвольной строки из БД.
     * Неизвестные (в т.ч. legacy) типы отображаются как есть.
     */
    public static function labelFor(string $type): string
    {
        return self::tryFrom($type)?->label()
            ?? ($type !== '' ? $type : 'unknown');
    }

    /**
     * Безопасный цвет бейджа для произвольной строки из БД.
     */
    public static function colorFor(string $type): string
    {
        return self::tryFrom($type)?->badgeColor() ?? 'gray';
    }

    /**
     * Значения типов, которые попадают в карточку сводки supplier-import.
     *
     * @return list<string>
     */
    public static function supplierImportSummaryValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->inSupplierImportSummary()),
        ));
    }
}
