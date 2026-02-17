<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('configures specs as a compact table repeater in product form', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));
    $specsField = $schema->getComponentByStatePath('specs', withHidden: true);

    expect($specsField)->toBeInstanceOf(Repeater::class);

    /** @var Repeater $specsField */
    $tableColumns = $specsField->getTableColumns();

    expect($specsField->isTable())->toBeTrue()
        ->and($specsField->isCompact())->toBeTrue()
        ->and($tableColumns)->toHaveCount(3)
        ->and($tableColumns[0]->getLabel())->toBe('Параметр')
        ->and($tableColumns[0]->isMarkedAsRequired())->toBeTrue()
        ->and($tableColumns[1]->getLabel())->toBe('Значение')
        ->and($tableColumns[1]->isMarkedAsRequired())->toBeTrue()
        ->and($tableColumns[2]->getLabel())->toBe('Источник');

    $sourceField = $specsField->getChildSchema()->getFlatFields(withHidden: true)['source'] ?? null;

    expect($sourceField)->toBeInstanceOf(Select::class);

    /** @var Select $sourceField */
    expect(array_keys($sourceField->getOptions()))->toBe([
        'manual',
        'jsonld',
        'inertia',
        'dom',
        'import',
        'legacy',
    ]);
});

it('normalizes specs state for persistence', function (): void {
    $normalized = ProductForm::normalizeSpecsState([
        ['name' => ' Объем бака ', 'value' => ' 60 л ', 'source' => 'jsonld'],
        ['name' => 'Объем бака', 'value' => '60 л', 'source' => 'dom'],
        ['name' => 'Вес', 'value' => '42 кг', 'source' => ''],
        ['name' => 'Напряжение', 'value' => 220, 'source' => true],
        ['name' => '', 'value' => 'x', 'source' => 'dom'],
        ['name' => 'Шум', 'value' => null, 'source' => 'dom'],
        'invalid',
    ]);

    expect($normalized)->toBe([
        ['name' => 'Объем бака', 'value' => '60 л', 'source' => 'jsonld'],
        ['name' => 'Вес', 'value' => '42 кг', 'source' => 'manual'],
        ['name' => 'Напряжение', 'value' => '220', 'source' => '1'],
    ]);

    expect(ProductForm::normalizeSpecsState([
        ['name' => '', 'value' => '', 'source' => null],
        ['name' => ' ', 'value' => ' ', 'source' => 'manual'],
    ]))->toBeNull();
});
