<?php

namespace App\Filament\Resources\Attributes\Schemas;

use App\Models\Unit;
use App\Models\Attribute;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;

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

                Select::make('filter_ui')
                    ->label('Тип фильтра')
                    ->options([
                        'select'      => 'Опция (один вариант)',
                        'multiselect' => 'Опции (несколько)',
                        'text'        => 'Произвольный текст',
                        'number'      => 'Число',
                        'range'       => 'Диапазон в товаре (min—max)',
                        'boolean'     => 'Да / Нет',
                    ])
                    ->required()
                    ->native(false)
                    ->live(),

                TextEntry::make('info')
                    ->state(<<<'HTML'
<p><strong>Опция (один вариант)</strong> — У одного товара может быть одно значение выбираемое из заранее сотавленного списка значений. Отображается как плитки выбора.<br> Добавить опции можно в соответствующей вкладке ниже, которая появтся только после сохранения.<br>
<em>Пример:</em> «Производитель»: Kraton</p>
<br>
<p><strong>Опции (несколько)</strong> — Аналогично "Опция (один вариант)" но, один товар может иметь несколько значений. Отображается тоже как плитки выбора.<br>  Добавить опции можно в соответствующей вкладке ниже, которая появтся только после сохранения.<br>
<em>Пример:</em> «Напряжение»: 220 В и 380 В</p>
<br>
<p><strong>Произвольный текст</strong> — Любое текстовое значение у каждого товара, не ограниченоое заранее. Присутствует исторически. Желательно перевод в один из предыдуших типов. <br>
<em>Пример:</em> «Тип двигателя». Отображается тоже как плитки выбора</p>
<br>
<p><strong>Число</strong> — произвольлное числовое значение у каждого товара, участвующего в фильтрах. Отображается как ползунок<br>
<em>Пример:</em> «Мощность, Вт»</p>
<br>
<p><strong>Диапазон в товаре (min–max)</strong> — два числа внутри товара<br>
<em>Пример:</em> «Диапазон давления 1–10 бар»</p>
<br>
<p><strong>Да / Нет</strong> — логический признак<br>
<em>Пример:</em> «Есть защита от перегрева». Отображается как тумблер</p>
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
                            ->mapWithKeys(fn(string $dim) => [
                                $dim => $labels[$dim] ?? $dim,
                            ])
                            ->toArray();
                    })
                    ->helperText('Определяет, какие единицы будут доступны для этого атрибута.')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live() // чтобы unit_id / units_pivot реагировали на смену dimension
                    ->visible(fn(Get $get) => in_array($get('filter_ui'), ['number', 'range'], true))
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
                                    $label .= ' (' . $unit->symbol . ')';
                                }

                                if ($unit->dimension) {
                                    $label .= ' — ' . $unit->dimension;
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
                    ->visible(fn(Get $get) => in_array($get('filter_ui'), ['number', 'range'], true)),

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
                                    $label .= ' (' . $unit->symbol . ')';
                                }

                                if ($unit->dimension) {
                                    $label .= ' — ' . $unit->dimension;
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
                    ->visible(fn(Get $get) => in_array($get('filter_ui'), ['number', 'range'], true))
                    ->afterStateHydrated(function ($component, ?Attribute $record) {
                        if (! $record || ! $record->exists) {
                            $component->state([]);
                            return;
                        }

                        $ids = $record->units()
                            ->pluck('units.id')
                            ->filter(fn($id) => $id !== $record->unit_id)
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
                    ->visible(fn(Get $get) => in_array($get('filter_ui'), ['number', 'range'], true)),

                TextInput::make('number_step')
                    ->numeric()
                    ->label('Шаг значений')
                    ->helperText('Напр. 1, 0.1, 0.01')
                    ->visible(fn(Get $get) => in_array($get('filter_ui'), ['number', 'range'], true)),

                Select::make('number_rounding')
                    ->label('Округление')
                    ->options([
                        'round' => 'Округлять',
                        'floor' => 'Вниз',
                        'ceil'  => 'Вверх',
                    ])
                    ->placeholder('— Не округлять —') // null
                    ->nullable()
                    ->default(null)
                    ->native(false)
                    ->visible(fn(Get $get) => in_array($get('filter_ui'), ['number', 'range'], true))
                    ->rules([
                        'nullable',
                        Rule::in(['round', 'floor', 'ceil']),
                    ])
            ]);
    }
}
