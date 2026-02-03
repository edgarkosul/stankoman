<?php

use Livewire\Livewire;

test('header search component renders', function () {
    Livewire::test('header.search')
        ->assertSee('Поиск по каталогу товаров')
        ->assertSeeHtml('type="search"');
});
