<?php

use App\Filament\Resources\Menus\Pages\BuilderMenu;

test('builder menu uses a non static view property', function () {
    $property = new ReflectionProperty(BuilderMenu::class, 'view');

    expect($property->isStatic())->toBeFalse();
});

test('builder menu view points to the correct blade view', function () {
    $defaults = (new ReflectionClass(BuilderMenu::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.resources.menus.pages.builder-menu');
});
