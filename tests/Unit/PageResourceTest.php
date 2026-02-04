<?php

use App\Filament\Resources\Pages\PageResource;

test('page resource navigation group is menu', function () {
    expect(PageResource::getNavigationGroup())->toBe('Меню');
});
