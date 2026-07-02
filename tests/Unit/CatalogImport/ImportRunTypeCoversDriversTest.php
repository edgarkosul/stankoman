<?php

use App\Support\CatalogImport\Drivers\Contracts\SupplierImportDriver;
use App\Support\CatalogImport\Drivers\ImportDriverRegistry;
use App\Support\CatalogImport\Enums\ImportRunType;

/**
 * Страховка от «тихого» бага: если новый supplier-драйвер зарегистрирован,
 * но его run_type забыли добавить в ImportRunType, метки/цвета/whitelist
 * молча ломаются (сырой ключ, выпадение из карточки сводки). Здесь это
 * превращается в красный тест вместо продакшен-сюрприза.
 */
it('registers every driver run type as an ImportRunType case', function () {
    $drivers = app(ImportDriverRegistry::class)->all();

    expect($drivers)->not->toBeEmpty();

    foreach ($drivers as $driver) {
        /** @var SupplierImportDriver $driver */
        $importType = $driver->importRunType();

        expect(ImportRunType::tryFrom($importType))
            ->not->toBeNull(
                "Driver [{$driver->key()}] importRunType() '{$importType}' отсутствует как case в ImportRunType.",
            );

        $deactivationType = $driver->deactivationRunType();

        if (is_string($deactivationType) && $deactivationType !== '') {
            expect(ImportRunType::tryFrom($deactivationType))
                ->not->toBeNull(
                    "Driver [{$driver->key()}] deactivationRunType() '{$deactivationType}' отсутствует как case в ImportRunType.",
                );
        }
    }
});

/**
 * И обратная связь: каждый run_type supplier-драйвера должен попадать в
 * карточку сводки supplier-import (inSupplierImportSummary), иначе прогон
 * не будет виден на экране запуска (баг с «залипшим» старым прогоном).
 */
it('includes every driver import run type in the supplier-import summary whitelist', function () {
    $summary = ImportRunType::supplierImportSummaryValues();

    foreach (app(ImportDriverRegistry::class)->all() as $driver) {
        /** @var SupplierImportDriver $driver */
        expect(in_array($driver->importRunType(), $summary, true))
            ->toBeTrue(
                "Driver [{$driver->key()}] importRunType() не входит в supplierImportSummaryValues().",
            );
    }
});
