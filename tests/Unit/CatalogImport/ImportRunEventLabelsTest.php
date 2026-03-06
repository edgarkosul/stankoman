<?php

use App\Support\CatalogImport\Runs\ImportRunEventLabels;

it('provides russian labels for known import run event stages and results', function (): void {
    expect(ImportRunEventLabels::stageLabel('processing'))->toBe('Обработка');
    expect(ImportRunEventLabels::resultLabel('created'))->toBe('Создан');
    expect(ImportRunEventLabels::resultLabel('updated'))->toBe('Обновлен');
    expect(ImportRunEventLabels::resultLabel('unchanged'))->toBe('Без изменений');
});

it('returns original values for unknown import run event stage and result codes', function (): void {
    expect(ImportRunEventLabels::stageLabel('custom_stage'))->toBe('custom_stage');
    expect(ImportRunEventLabels::resultLabel('custom_result'))->toBe('custom_result');
});
