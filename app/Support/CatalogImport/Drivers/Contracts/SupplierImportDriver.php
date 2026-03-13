<?php

namespace App\Support\CatalogImport\Drivers\Contracts;

use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Drivers\DriverAvailability;

interface SupplierImportDriver
{
    public function key(): string;

    public function label(): string;

    public function availability(): DriverAvailability;

    public function isAvailableForSupplier(?Supplier $supplier): bool;

    public function profileKey(): string;

    public function defaultSourceName(): string;

    public function supportsScope(): bool;

    public function supportsDeactivation(): bool;

    public function defaultSettings(): array;

    public function settingsSchema(): array;

    public function importRuntimeSchema(): array;

    public function deactivationRuntimeSchema(): array;

    public function normalizeSettings(array $settings): array;

    public function sourceLabel(array $settings): string;

    public function importRunType(): string;

    public function deactivationRunType(): ?string;

    public function buildImportOptions(SupplierImportSource $source, array $runtime): array;

    public function buildDeactivationOptions(SupplierImportSource $source, array $runtime): array;

    public function dispatchImport(ImportRun $run, array $options, bool $write): void;

    public function dispatchDeactivation(ImportRun $run, array $options, bool $write): void;
}
