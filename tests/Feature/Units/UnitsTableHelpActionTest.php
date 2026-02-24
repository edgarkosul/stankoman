<?php

use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\User;
use Livewire\Livewire;

test('units page has instructions header action in unified style', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListUnits::class)
        ->assertActionExists('instructions')
        ->assertActionHasLabel('instructions', 'Инструкция')
        ->assertActionHasIcon('instructions', 'heroicon-o-question-mark-circle')
        ->assertActionHasColor('instructions', 'gray')
        ->assertActionHasUrl('instructions', 'https://help.stankoman.ru/units/')
        ->assertActionShouldOpenUrlInNewTab('instructions');
});
