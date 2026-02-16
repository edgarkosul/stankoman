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
                        $dataType = (string) ($state ?? 'text');
                        $valueSource = (string) ($get('value_source') ?? '');
                        $availableValueSources = AttributeResource::valueSourceOptionsForDataType($dataType);

                        if (! array_key_exists($valueSource, $availableValueSources)) {
                            $valueSource = AttributeResource::defaultValueSourceForDataType($dataType);
                            $set('value_source', $valueSource);
                        }

                        $filterUi = (string) ($get('filter_ui') ?? '');
                        $availableFilterUis = AttributeResource::filterUiOptionsFor($dataType, $valueSource);

                        if ($availableFilterUis === []) {
                            $set('filter_ui', null);

                            return;
                        }

                        if (! array_key_exists($filterUi, $availableFilterUis)) {
                            $set('filter_ui', AttributeResource::defaultFilterUiFor($dataType, $valueSource));
                        }
                    }),

                Select::make('value_source')
                    ->label('Источник значения')
                    ->options(fn (Get $get): array => AttributeResource::valueSourceOptionsForDataType(
                        (string) ($get('data_type') ?? 'text')
                    ))
                    ->required()
                    ->native(false)
                    ->live()
                    ->default(fn (): string => AttributeResource::defaultValueSourceForDataType('text'))
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        $dataType = (string) ($get('data_type') ?? 'text');
                        $valueSource = (string) ($state ?? '');
                        $availableFilterUis = AttributeResource::filterUiOptionsFor($dataType, $valueSource);

                        if ($availableFilterUis === []) {
                            $set('filter_ui', null);

                            return;
                        }

                        $filterUi = (string) ($get('filter_ui') ?? '');

                        if (! array_key_exists($filterUi, $availableFilterUis)) {
                            $set('filter_ui', AttributeResource::defaultFilterUiFor($dataType, $valueSource));
                        }
                    })
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $dataType = (string) ($get('data_type') ?? 'text');
                            $allowedValueSources = array_keys(AttributeResource::valueSourceOptionsForDataType(
                                $dataType
                            ));

                            if (! in_array((string) $value, $allowedValueSources, true)) {
                                $fail('Недопустимая комбинация типа данных и источника значения.');
                            }
                        },
                    ]),

                Select::make('filter_ui')
                    ->label('Отображение фильтра в UI')
                    ->options(fn (Get $get): array => AttributeResource::filterUiOptionsFor(
                        (string) ($get('data_type') ?? 'text'),
                        (string) ($get('value_source') ?? 'free')
                    ))
                    ->required(fn (Get $get): bool => AttributeResource::filterUiOptionsFor(
                        (string) ($get('data_type') ?? 'text'),
                        (string) ($get('value_source') ?? 'free')
                    ) !== [])
                    ->native(false)
                    ->live()
                    ->default(fn (): ?string => AttributeResource::defaultFilterUiFor('text', 'options'))
                    ->visible(fn (Get $get): bool => AttributeResource::filterUiOptionsFor(
                        (string) ($get('data_type') ?? 'text'),
                        (string) ($get('value_source') ?? 'free')
                    ) !== [])
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $allowedFilterUis = array_keys(AttributeResource::filterUiOptionsFor(
                                (string) ($get('data_type') ?? 'text'),
                                (string) ($get('value_source') ?? 'free')
                            ));

                            if ($allowedFilterUis === []) {
                                return;
                            }

                            if (! in_array((string) $value, $allowedFilterUis, true)) {
                                $fail('Недопустимый тип отображения фильтра для выбранной комбинации.');
                            }
                        },
                    ]),

                TextEntry::make('info')
                    ->state(<<<'HTML'
<p><strong>Тип данных</strong> определяет, как значение хранится у товара.</p>
<p><strong>Источник значения</strong> определяет, вводится ли значение вручную или выбирается из заранее созданных опций.</p>
<p><strong>Отображение фильтра в UI</strong> влияет только на визуальный тип выбора на витрине/в админке.</p>
<br>
<p><strong>Допустимые комбинации:</strong></p>
<ul>
<li><code>text</code> + <code>free</code> (свободный ввод)</li>
<li><code>text</code> + <code>options</code> + <code>tiles|dropdown</code> (выбор из опций)</li>
<li><code>number|range|boolean</code> + <code>free</code></li>
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
