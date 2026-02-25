<?php

use App\Filament\Pages\ProductImportExport;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('includes category and status filters in product import export form', function () {
    $page = new ProductImportExport;
    $schema = $page->form(Schema::make($page));

    $categoryField = $schema->getComponent(
        fn ($component) => $component instanceof Select && $component->getName() === 'filter_category_ids',
    );
    $onlyActiveField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'filter_only_active',
    );
    $onlyStockField = $schema->getComponent(
        fn ($component) => $component instanceof Toggle && $component->getName() === 'filter_only_stock',
    );

    expect($categoryField)->not->toBeNull();
    expect($onlyActiveField)->not->toBeNull();
    expect($onlyStockField)->not->toBeNull();
    expect($categoryField->isMultiple())->toBeTrue();
});

it('normalizes import filters from page state', function () {
    $page = new ProductImportExport;
    $page->data = [
        'filter_category_ids' => [5, '11', null, '', 'abc', 5, '0', -3],
        'filter_only_active' => 1,
        'filter_only_stock' => false,
    ];

    $method = new ReflectionMethod(ProductImportExport::class, 'buildImportFilters');
    $method->setAccessible(true);

    $filters = $method->invoke($page);

    expect($filters)->toBe([
        'category_ids' => [5, 11],
        'only_active' => true,
        'only_stock' => false,
    ]);
});
