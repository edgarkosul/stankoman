<?php

it('resolves favorites route to favorites page path', function (): void {
    expect(route('favorites.index', [], false))->toBe('/favorites');
});

it('renders empty favorites page', function (): void {
    $this->get(route('favorites.index'))
        ->assertSuccessful()
        ->assertSee('Избранное')
        ->assertSee('В избранном ничего не найдено.');
});
