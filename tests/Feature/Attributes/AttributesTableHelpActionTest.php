<?php

use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Models\User;
use Livewire\Livewire;

test('attributes page has instructions header action in unified style', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListAttributes::class)
        ->assertActionExists('instructions')
        ->assertActionHasLabel('instructions', 'Инструкция')
        ->assertActionHasIcon('instructions', 'heroicon-o-question-mark-circle')
        ->assertActionHasColor('instructions', 'gray')
        ->assertActionHasUrl('instructions', 'https://help.stankoman.ru/attributes/')
        ->assertActionShouldOpenUrlInNewTab('instructions');
});
