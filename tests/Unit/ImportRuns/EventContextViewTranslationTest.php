<?php

use Tests\TestCase;

uses(TestCase::class);

it('renders russian product field labels in event context modal', function (): void {
    $html = view('filament.import-runs.event-context', [
        'context' => [
            'changes' => [
                'price_amount' => [
                    'before' => 142534,
                    'after' => 146133,
                ],
                'is_active' => [
                    'before' => true,
                    'after' => false,
                ],
            ],
            'other_changed_fields' => [
                'description',
                'extra_description',
                'specs',
                'meta_description',
            ],
            'deferred_changes' => [
                'image',
                'thumb',
                'gallery',
            ],
            'media' => [
                'queued' => 4,
                'reused' => 0,
                'deduplicated' => 0,
            ],
        ],
        'json' => null,
    ])->render();

    expect($html)->toContain('Цена')
        ->and($html)->toContain('На сайте')
        ->and($html)->toContain('Описание')
        ->and($html)->toContain('Доп. описание')
        ->and($html)->toContain('Характеристики')
        ->and($html)->toContain('META Description')
        ->and($html)->toContain('Изображение')
        ->and($html)->toContain('Превью')
        ->and($html)->toContain('Галерея')
        ->and($html)->not->toContain('price_amount')
        ->and($html)->not->toContain('is_active')
        ->and($html)->not->toContain('extra_description');
});
