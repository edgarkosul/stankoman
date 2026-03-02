<?php

use Livewire\Livewire;

test('header search component renders live search markup', function (): void {
    Livewire::test('header.search')
        ->assertSee('Поиск по каталогу товаров')
        ->assertSeeHtml('wire:submit.prevent="goFull"')
        ->assertSeeHtml('wire:model.live.debounce.300ms="q"')
        ->assertSeeHtml('role="combobox"');
});

test('header search full action redirects to search page', function (): void {
    Livewire::test('header.search')
        ->call('goFull')
        ->assertRedirect(route('search', ['q' => '']));
});
