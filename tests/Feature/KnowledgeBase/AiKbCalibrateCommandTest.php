<?php

use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\KbVectorIndexer;

it('отказывается мерить пустую базу', function (): void {
    $this->artisan('ai:kb-calibrate')
        ->expectsOutputToContain('База знаний пуста')
        ->assertFailed();
});

it('проходит все размеченные вопросы и советует порог', function (): void {
    // На заглушке оценки случайны — проверяется тракт команды, а не сам порог:
    // его меряют на деве настоящим эмбеддером.
    app(KbVectorIndexer::class)->indexDocument('intertooler-page', new KbDocument(
        key: 'dostavka-i-oplata',
        title: 'Доставка и оплата',
        breadcrumb: ['InterTooler.ru', 'Доставка и оплата'],
        text: "## Доставка\n\nВезём по всей России.\n\n## Оплата\n\nПо счёту.",
    ));

    $this->artisan('ai:kb-calibrate')
        ->expectsOutputToContain('Какая погода в Москве?')
        ->expectsOutputToContain('Текущий порог')
        ->expectsOutputToContain('Рекомендуемый порог')
        ->assertSuccessful();
});
