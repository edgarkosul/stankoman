<?php

use App\Filament\Resources\Attributes\AttributeResource;

dataset('attribute_type_pairs', [
    'text + text' => [
        ['data_type' => 'text', 'input_type' => 'text'],
        'text',
        'text',
    ],
    'text + select' => [
        ['data_type' => 'text', 'input_type' => 'select'],
        'text',
        'select',
    ],
    'text + multiselect' => [
        ['data_type' => 'text', 'input_type' => 'multiselect'],
        'text',
        'multiselect',
    ],
    'number + number' => [
        ['data_type' => 'number', 'input_type' => 'number'],
        'number',
        'number',
    ],
    'range + range' => [
        ['data_type' => 'range', 'input_type' => 'range'],
        'range',
        'range',
    ],
    'boolean + boolean' => [
        ['data_type' => 'boolean', 'input_type' => 'boolean'],
        'boolean',
        'boolean',
    ],
    'number + invalid input type' => [
        ['data_type' => 'number', 'input_type' => 'select'],
        'number',
        'number',
    ],
    'missing input type' => [
        ['data_type' => 'text'],
        'text',
        'select',
    ],
    'unknown data type with valid text input type' => [
        ['data_type' => 'unknown', 'input_type' => 'select'],
        'text',
        'select',
    ],
    'unknown data type with invalid input type' => [
        ['data_type' => 'unknown', 'input_type' => 'unknown'],
        'text',
        'select',
    ],
    'missing data and input types' => [
        [],
        'text',
        'select',
    ],
]);

test('normalize type pair allows only valid combinations', function (array $data, string $expectedDataType, string $expectedInputType): void {
    $mapped = AttributeResource::normalizeTypePair($data);

    expect($mapped['data_type'])->toBe($expectedDataType)
        ->and($mapped['input_type'])->toBe($expectedInputType)
        ->and($mapped)->not->toHaveKey('filter_ui');
})->with('attribute_type_pairs');

test('input type options for text data type do not include text', function (): void {
    $options = AttributeResource::inputTypeOptionsForDataType('text');

    expect(array_keys($options))
        ->toBe(['select', 'multiselect'])
        ->and($options)->not->toHaveKey('text');
});
