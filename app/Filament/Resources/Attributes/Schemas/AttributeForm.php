<?php

namespace App\Filament\Resources\Attributes\Schemas;

use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;
use App\Models\Unit;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class AttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('ID')
                    ->disabled()
                    ->extraAttributes(['class' => 'w-32']),

                TextInput::make('name')
                    ->label('Название фильтра')
                    ->required(),

                Select::make('data_type')
                    ->label('Тип данных')
                    ->options(fn (): array => AttributeResource::dataTypeOptions())
                    ->required()
                    ->native(false)
                    ->live()
                    ->default('text')
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        $inputType = (string) ($get('input_type') ?? '');
                        $availableInputTypes = AttributeResource::inputTypeOptionsForDataType($state);

                        if (! array_key_exists($inputType, $availableInputTypes)) {
                            $set('input_type', AttributeResource::defaultInputTypeForDataType($state));
                        }
                    }),

                Select::make('input_type')
                    ->label('Тип ввода/фильтрации')
                    ->options(fn (Get $get): array => AttributeResource::inputTypeOptionsForDataType(
                        (string) ($get('data_type') ?? 'text')
                    ))
                    ->required()
                    ->native(false)
                    ->live()
                    ->default(fn (): string => AttributeResource::defaultInputTypeForDataType('text'))
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $dataType = (string) ($get('data_type') ?? 'text');
                            $allowedInputTypes = array_keys(AttributeResource::inputTypeOptionsForDataType(
                                $dataType
                            ));

                            if ($dataType === 'text' && (string) $value === 'text') {
                                return;
                            }

                            if (! in_array((string) $value, $allowedInputTypes, true)) {
                                $fail('Недопустимая комбинация типа данных и типа ввода.');
                            }
                        },
                    ]),

                TextEntry::make('info')
                    ->state(<<<'HTML'
<p><strong>Тип данных</strong> определяет, как значение хранится у товара.</p>
<p><strong>Тип ввода/фильтрации</strong> определяет, как это значение вводится в админке и как используется в фильтрах.</p>
<br>
<p><strong>Допустимые комбинации:</strong></p>
<ul>
<li><code>text</code> → <code>select</code>, <code>multiselect</code></li>
<li><code>number</code> → <code>number</code></li>
<li><code>range</code> → <code>range</code></li>
<li><code>boolean</code> → <code>boolean</code></li>
</ul>
HTML)
                    ->html()
                    ->hiddenLabel()
                    ->columnSpanFull(),

                Toggle::make('is_filterable')
                    ->label('Использовать в фильтрах')
                    ->default(true),

                Toggle::make('is_comparable')
                    ->label('Использовать в сравнениях')
                    ->default(false),

                // ---------- Измерение / единицы: только для number / range ----------

                Select::make('dimension')
                    ->label('Измерение (семейство единиц)')
                    ->options(function () {
                        // Все человекопонятные подписи
                        $labels = Unit::dimensionOptions();

                        // Только реально существующие измерения
                        $dims = Unit::query()
                            ->whereNotNull('dimension')
                            ->distinct()
                            ->orderBy('dimension')
                            ->pluck('dimension')
                            ->all();

                        return collect($dims)
                            ->mapWithKeys(fn (string $dim) => [
                                $dim => $labels[$dim] ?? $dim,
                            ])
                            ->toArray();
                    })
                    ->helperText('Определяет, какие единицы будут доступны для этого атрибута.')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live() // чтобы unit_id / units_pivot реагировали на смену dimension
                    ->visible(fn (Get $get) => in_array($get('data_type'), ['number', 'range'], true))
                    ->afterStateHydrated(function ($component, $state, Get $get) {
                        // Если dimension уже есть — ничего не делаем
                        if ($state) {
                            return;
                        }

                        // Пытаемся подтянуть dimension из выбранного unit_id (для старых атрибутов)
                        $unitId = $get('unit_id');
                        if (! $unitId) {
                            return;
                        }

                        $dimension = Unit::query()
                            ->whereKey($unitId)
                            ->value('dimension');

                        if ($dimension) {
                            $component->state($dimension);
                        }
                    }),

                // Базовая единица измерения (одна, legacy unit_id)
                Select::make('unit_id')
                    ->label('Единица измерения по умолчанию')
                    ->options(function (Get $get) {
                        $dimension = $get('dimension');

                        $query = Unit::query();

                        if ($dimension) {
                            $query->where('dimension', $dimension);
                        }

                        return $query
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(function (Unit $unit) {
                                $label = $unit->name;

                                if ($unit->symbol) {
                                    $label .= ' ('.$unit->symbol.')';
                                }

                                if ($unit->dimension) {
                                    $label .= ' — '.$unit->dimension;
                                }

                                return [$unit->id => $label];
                            })
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Базовая единица для этого атрибута.')
                    ->rules([
                        'nullable',
                        'exists:units,id',
                    ])
                    ->visible(fn (Get $get) => in_array($get('data_type'), ['number', 'range'], true)),

                // Дополнительные единицы измерения (через pivot attribute_unit)
                Select::make('units_pivot')
                    ->label('Дополнительные единицы')
                    ->helperText('Все единицы в том же измерении. Основная выбирается выше.')
                    ->options(function (Get $get) {
                        $dimension = $get('dimension');

                        $query = Unit::query();

                        if ($dimension) {
                            $query->where('dimension', $dimension);
                        }

                        return $query
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(function (Unit $unit) {
                                $label = $unit->name;

                                if ($unit->symbol) {
                                    $label .= ' ('.$unit->symbol.')';
                                }

                                if ($unit->dimension) {
                                    $label .= ' — '.$unit->dimension;
                                }

                                return [$unit->id => $label];
                            })
                            ->toArray();
                    })
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (Get $get) => in_array($get('data_type'), ['number', 'range'], true))
                    ->afterStateHydrated(function ($component, ?Attribute $record) {
                        if (! $record || ! $record->exists) {
                            $component->state([]);

                            return;
                        }

                        $ids = $record->units()
                            ->pluck('units.id')
                            ->filter(fn ($id) => $id !== $record->unit_id)
                            ->values()
                            ->all();

                        $component->state($ids);
                    }),

                // ---------- Параметры числа ----------

                TextInput::make('number_decimals')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(6)
                    ->label('Знаков после запятой')
                    ->visible(fn (Get $get) => in_array($get('data_type'), ['number', 'range'], true)),

                TextInput::make('number_step')
                    ->numeric()
                    ->label('Шаг значений')
                    ->helperText('Напр. 1, 0.1, 0.01')
                    ->visible(fn (Get $get) => in_array($get('data_type'), ['number', 'range'], true)),

                Select::make('number_rounding')
                    ->label('Округление')
                    ->options([
                        'round' => 'Округлять',
                        'floor' => 'Вниз',
                        'ceil' => 'Вверх',
                    ])
                    ->placeholder('— Не округлять —') // null
                    ->nullable()
                    ->default(null)
                    ->native(false)
                    ->visible(fn (Get $get) => in_array($get('data_type'), ['number', 'range'], true))
                    ->rules([
                        'nullable',
                        Rule::in(['round', 'floor', 'ceil']),
                    ]),
            ]);
    }
}
