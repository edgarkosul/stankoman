<?php

use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Models\Unit;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

test('admin can create a custom unit family and base symbol from the unit form', function (): void {
    $user = User::factory()->create();

    config([
        'filament_admin.emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);

    Livewire::test(CreateUnit::class)
        ->assertFormComponentActionExists('dimension', 'createOption')
        ->callFormComponentAction('dimension', 'createOption', [
            'name' => 'Диапазон затемнения сварочных масок',
        ])
        ->assertFormSet([
            'dimension' => 'Диапазон затемнения сварочных масок',
        ])
        ->assertFormComponentActionExists('base_symbol', 'createOption')
        ->callFormComponentAction('base_symbol', 'createOption', [
            'value' => 'DIN',
        ])
        ->assertFormSet([
            'base_symbol' => 'DIN',
        ])
        ->fillForm([
            'name' => 'DIN',
            'symbol' => 'DIN',
            'dimension' => 'Диапазон затемнения сварочных масок',
            'base_symbol' => 'DIN',
            'si_factor' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $unit = Unit::query()
        ->where('dimension', 'Диапазон затемнения сварочных масок')
        ->where('base_symbol', 'DIN')
        ->first();

    expect($unit)->not->toBeNull()
        ->and($unit?->name)->toBe('DIN')
        ->and($unit?->symbol)->toBe('DIN');

    Livewire::test(CreateAttribute::class)
        ->fillForm([
            'data_type' => 'number',
            'value_source' => 'free',
        ])
        ->assertFormFieldVisible('dimension')
        ->assertFormFieldExists('dimension', function (Select $field): bool {
            $options = $field->getOptions();

            return ($options['Диапазон затемнения сварочных масок'] ?? null) === 'Диапазон затемнения сварочных масок';
        });
});

test('admin can clear custom unit family selection before saving', function (): void {
    $user = User::factory()->create();

    config([
        'filament_admin.emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);

    Livewire::test(CreateUnit::class)
        ->fillForm([
            'dimension' => 'Временное семейство',
        ])
        ->assertFormSet([
            'dimension' => 'Временное семейство',
        ])
        ->fillForm([
            'dimension' => null,
        ])
        ->assertFormSet([
            'dimension' => null,
        ]);
});
