<?php

use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Models\Attribute;
use App\Models\User;
use Livewire\Livewire;

test('attributes table renders translated badge values for type source and ui', function (): void {
    $this->actingAs(User::factory()->create());

    $numberAttribute = Attribute::query()->create([
        'name' => 'Частота',
        'slug' => 'freq-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'filter_ui' => null,
        'input_type' => 'number',
        'is_filterable' => true,
    ]);

    $textAttribute = Attribute::query()->create([
        'name' => 'Цвет',
        'slug' => 'color-test',
        'data_type' => 'text',
        'value_source' => 'options',
        'filter_ui' => 'tiles',
        'input_type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $booleanAttribute = Attribute::query()->create([
        'name' => 'Инвертор',
        'slug' => 'inverter-test',
        'data_type' => 'boolean',
        'value_source' => 'free',
        'filter_ui' => null,
        'input_type' => 'boolean',
        'is_filterable' => true,
    ]);

    Livewire::test(ListAttributes::class)
        ->assertCanSeeTableRecords([$numberAttribute, $textAttribute, $booleanAttribute])
        ->assertTableColumnFormattedStateSet('data_type', 'Число', record: $numberAttribute)
        ->assertTableColumnFormattedStateSet('data_type', 'Текст', record: $textAttribute)
        ->assertTableColumnFormattedStateSet('data_type', 'Да / Нет', record: $booleanAttribute)
        ->assertTableColumnFormattedStateSet('value_source', 'Свободный ввод', record: $numberAttribute)
        ->assertTableColumnFormattedStateSet('value_source', 'Выбор из опций', record: $textAttribute)
        ->assertTableColumnFormattedStateSet('value_source', 'Свободный ввод', record: $booleanAttribute)
        ->assertTableColumnFormattedStateSet('filter_ui', 'бегунок', record: $numberAttribute)
        ->assertTableColumnFormattedStateSet('filter_ui', 'Плитки', record: $textAttribute)
        ->assertTableColumnFormattedStateSet('filter_ui', 'бегунок', record: $booleanAttribute);
});
