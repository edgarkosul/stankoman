<?php

use App\Filament\Resources\Attributes\AttributeResource;

dataset('attribute_type_pairs', [
    'text + free source' => [
        ['data_type' => 'text', 'value_source' => 'free'],
        'text',
        'free',
        null,
        'text',
    ],
    'text + options + tiles' => [
        ['data_type' => 'text', 'value_source' => 'options', 'filter_ui' => 'tiles'],
        'text',
        'options',
        'tiles',
        'multiselect',
    ],
    'text + options + dropdown' => [
        ['data_type' => 'text', 'value_source' => 'options', 'filter_ui' => 'dropdown'],
        'text',
        'options',
        'dropdown',
        'multiselect',
    ],
    'number + free source' => [
        ['data_type' => 'number', 'value_source' => 'free'],
        'number',
        'free',
        null,
        'number',
    ],
    'range + free source' => [
        ['data_type' => 'range', 'value_source' => 'free'],
        'range',
        'free',
        null,
        'range',
    ],
    'boolean + free source' => [
        ['data_type' => 'boolean', 'value_source' => 'free'],
        'boolean',
        'free',
        null,
        'boolean',
    ],
    'number + invalid source' => [
        ['data_type' => 'number', 'value_source' => 'options'],
        'number',
        'free',
        null,
        'number',
    ],
    'text + options without ui' => [
        ['data_type' => 'text', 'value_source' => 'options'],
        'text',
        'options',
        'tiles',
        'multiselect',
    ],
    'unknown data type with legacy select input' => [
        ['data_type' => 'unknown', 'input_type' => 'select'],
        'text',
        'options',
        'dropdown',
        'multiselect',
    ],
    'missing source and ui for text' => [
        ['data_type' => 'text'],
        'text',
        'options',
        'tiles',
        'multiselect',
    ],
    'missing data and source' => [
        [],
        'text',
        'options',
        'tiles',
        'multiselect',
    ],
]);

test('normalize type pair allows only valid combinations', function (
    array $data,
    string $expectedDataType,
    string $expectedValueSource,
    ?string $expectedFilterUi,
    string $expectedInputType,
): void {
    $mapped = AttributeResource::normalizeTypePair($data);

    expect($mapped['data_type'])->toBe($expectedDataType)
        ->and($mapped['value_source'])->toBe($expectedValueSource)
        ->and($mapped['filter_ui'])->toBe($expectedFilterUi)
        ->and($mapped['input_type'])->toBe($expectedInputType)
        ->and($mapped)->toHaveKeys(['data_type', 'value_source', 'input_type']);
})->with('attribute_type_pairs');

test('input type options for text data type do not include text', function (): void {
    $options = AttributeResource::inputTypeOptionsForDataType('text');

    expect(array_keys($options))
        ->toBe(['select', 'multiselect'])
        ->and($options)->not->toHaveKey('text');
});

test('value source options for number data type only allow free source', function (): void {
    expect(AttributeResource::valueSourceOptionsForDataType('number'))
        ->toBe(['free' => 'Свободный ввод']);
});
