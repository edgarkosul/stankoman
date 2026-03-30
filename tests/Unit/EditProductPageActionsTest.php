<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use Tests\TestCase;

uses(TestCase::class);

test('edit product page has sync specs to attributes header action', function (): void {
    $page = new EditProduct;

    $method = new ReflectionMethod(EditProduct::class, 'getHeaderActions');
    $method->setAccessible(true);

    $actions = $method->invoke($page);
    $actionNames = collect($actions)
        ->map(fn ($action): string => $action->getName())
        ->all();

    expect($actionNames)->toContain('sync_specs_to_attributes');
});

test('edit product page has save header action bound to the form', function (): void {
    $page = new EditProduct;

    $method = new ReflectionMethod(EditProduct::class, 'getHeaderActions');
    $method->setAccessible(true);

    $actions = collect($method->invoke($page));

    $saveAction = $actions->first(
        fn ($action): bool => $action->getName() === 'save'
    );

    expect($saveAction)->not->toBeNull()
        ->and($saveAction->getFormId())->toBe('form');
});
