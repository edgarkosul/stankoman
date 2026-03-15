<?php

namespace App\Support\CatalogImport\Drivers;

use App\Models\Supplier;
use App\Support\CatalogImport\Drivers\Contracts\SupplierImportDriver;

class ImportDriverRegistry
{
    /**
     * @var array<string, SupplierImportDriver>|null
     */
    private ?array $drivers = null;

    public function __construct(
        private readonly VactoolHtmlDriver $vactool,
        private readonly MetalmasterHtmlDriver $metalmaster,
        private readonly MetaltecXmlDriver $metaltec,
        private readonly YandexMarketFeedDriver $yandex,
    ) {}

    /**
     * @return array<string, SupplierImportDriver>
     */
    public function all(): array
    {
        if ($this->drivers !== null) {
            return $this->drivers;
        }

        $this->drivers = collect([
            $this->vactool,
            $this->metalmaster,
            $this->metaltec,
            $this->yandex,
        ])
            ->mapWithKeys(fn (SupplierImportDriver $driver): array => [$driver->key() => $driver])
            ->all();

        return $this->drivers;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->all())
            ->mapWithKeys(fn (SupplierImportDriver $driver): array => [$driver->key() => $driver->label()])
            ->all();
    }

    /**
     * @return array<string, SupplierImportDriver>
     */
    public function availableForSupplier(?Supplier $supplier, ?string $includeKey = null): array
    {
        return collect($this->all())
            ->filter(function (SupplierImportDriver $driver, string $key) use ($supplier, $includeKey): bool {
                return $driver->isAvailableForSupplier($supplier)
                    || ($includeKey !== null && $key === $includeKey);
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function optionsForSupplier(?Supplier $supplier, ?string $includeKey = null): array
    {
        return collect($this->availableForSupplier($supplier, $includeKey))
            ->mapWithKeys(fn (SupplierImportDriver $driver): array => [$driver->key() => $driver->label()])
            ->all();
    }

    public function get(?string $key): ?SupplierImportDriver
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return $this->all()[$key] ?? null;
    }

    public function default(): SupplierImportDriver
    {
        return $this->yandex;
    }

    public function defaultForSupplier(?Supplier $supplier, ?string $includeKey = null): SupplierImportDriver
    {
        return collect($this->availableForSupplier($supplier, $includeKey))
            ->first() ?? $this->default();
    }
}
