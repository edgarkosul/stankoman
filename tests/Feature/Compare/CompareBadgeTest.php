<?php

use App\Livewire\Header\CompareBadge;
use Livewire\Livewire;

it('redirects from compare badge when compare list has items', function (): void {
    session()->put('compare.ids', [10]);

    Livewire::test(CompareBadge::class)
        ->call('goToComparePage')
        ->assertRedirect(route('compare.index'));
});

it('does not redirect from compare badge when compare list is empty', function (): void {
    session()->forget('compare.ids');

    Livewire::test(CompareBadge::class)
        ->call('goToComparePage')
        ->assertNoRedirect();
});
