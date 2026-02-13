<?php

use App\Filament\Resources\Attributes\AttributeResource;

dataset('attribute_filter_ui_mappings', [
    'select' => [
        ['filter_ui' => 'select'],
        'select',
        'text',
    ],
    'multiselect' => [
        ['filter_ui' => 'multiselect'],
        'multiselect',
        'text',
    ],
    'number' => [
        ['filter_ui' => 'number'],
        'number',
        'number',
    ],
    'range' => [
        ['filter_ui' => 'range'],
        'range',
        'range',
    ],
    'boolean' => [
        ['filter_ui' => 'boolean'],
        'boolean',
        'boolean',
    ],
    'text' => [
        ['filter_ui' => 'text'],
        'text',
        'text',
    ],
    'missing filter_ui' => [
        [],
        'text',
        'text',
    ],
]);

test('apply ui map always sets non-null input and data types', function (array $data, string $expectedInputType, string $expectedDataType): void {
    $mapped = AttributeResource::applyUiMap($data);

    expect($mapped['input_type'])->toBe($expectedInputType)
        ->and($mapped['data_type'])->toBe($expectedDataType)
        ->and($mapped)->not->toHaveKey('filter_ui');
})->with('attribute_filter_ui_mappings');
