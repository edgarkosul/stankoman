<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;
use App\Models\Unit;
use App\Support\FilterSchemaCache;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AttributeDefsRelationManager extends RelationManager
{
    protected static string $relationship = 'attributeDefs';

    protected static ?string $title = 'Фильтры используемые в категории';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->children()->doesntExist();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // Юнит отображения
            Select::make('display_unit_id')
                ->label('Единица отображения')
                ->helperText('Определяет, в какой единице показывать этот атрибут в категории.')
                ->options(function (?Attribute $record) {
                    if (! $record) {
                        return [];
                    }

                    // 1. Подтягиваем все доп. единицы из attribute_unit
                    $units = $record->units()
                        ->orderByPivot('sort_order')
                        ->get()
                        ->keyBy('id');

                    // 2. Гарантируем, что базовая unit_id тоже есть в списке
                    if ($record->unit && ! $units->has($record->unit_id)) {
                        $units->prepend($record->unit, $record->unit_id);
                    }

                    // 3. Формируем человекочитаемые подписи
                    return $units
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
                ->native(false),

            // --- Числовой формат для этой категории ---
            TextInput::make('number_decimals')
                ->numeric()
                ->minValue(0)
                ->maxValue(6)
                ->label('Знаков после запятой (для категории)')
                ->helperText('Если пусто — используется настройка атрибута.')
                ->nullable()
                ->visible(fn (?Attribute $record) => in_array($record?->data_type, ['number', 'range'], true)),

            TextInput::make('number_step')
                ->numeric()
                ->label('Шаг значений (фильтр)')
                ->helperText('Если пусто — шаг вычисляется из глобальных настороек фильтра.')
                ->nullable()
                ->visible(fn (?Attribute $record) => in_array($record?->data_type, ['number', 'range'], true)),
            Select::make('number_rounding')
                ->label('Округление (для категории)')
                ->options([
                    'round' => 'Округлять',
                    'floor' => 'Вниз',
                    'ceil' => 'Вверх',
                ])
                ->placeholder('— Использовать настройку атрибута —')
                ->nullable()
                ->native(false)
                ->visible(fn (?Attribute $record) => in_array($record?->data_type, ['number', 'range'], true)),

            Toggle::make('visible_in_specs')->label('Показывать в карточке товара'),
            Toggle::make('visible_in_compare')->label('Показывать в сравнении'),
            Toggle::make('is_required')->label('Обязательный для категории'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('filter_order')
            ->afterReordering(function (): void {
                $this->invalidateCategoryFiltersCache();
            })
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('id')->searchable(),
                TextColumn::make('name')
                    ->label('Фильтр')
                    ->searchable()
                    ->url(fn ($record) => AttributeResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab() // если нужно открывать в новой вкладке
                ,
                TextColumn::make('pivot.display_unit_id')
                    ->label('Ед. изм.')
                    ->formatStateUsing(function ($state, Attribute $record) {
                        static $units = null;

                        // Ленивая загрузка кэша всех юнитов (id => символ)
                        if ($units === null) {
                            $units = Unit::query()
                                ->select('id', 'symbol')
                                ->get()
                                ->keyBy('id');
                        }

                        // Если в pivot явно выбран юнит отображения — используем его
                        if ($state && isset($units[$state])) {
                            $symbol = (string) $units[$state]->symbol;

                            return $symbol !== '' ? $symbol : '—';
                        }

                        // Иначе фоллбек на defaultUnit атрибута
                        $unit = $record->defaultUnit();
                        $symbol = $unit?->symbol ?? '';

                        return $symbol !== '' ? $symbol : '—';
                    })
                    ->toggleable(),
                // 🔹 Знаков после запятой для КАТЕГОРИИ
                TextColumn::make('pivot.number_decimals')
                    ->numeric()
                    ->label('Знаков после запятой'),

                // 🔹 Шаг значений для КАТЕГОРИИ
                TextColumn::make('pivot.number_step')
                    ->numeric()
                    ->label('Шаг значений'),
                TextColumn::make('filter_order')->numeric()->label('Порядок отображения'),
                IconColumn::make('visible_in_specs')->boolean()->label('Видимость'),

            ])
            ->paginated(false)
            // делаем таблицу полностью информативной
            ->headerActions([
                AttachAction::make()->label('Добавить')
                    ->modalHeading('Добавить фильтр')
                    ->recordTitle(fn (Attribute $record) => $this->attributeLabel($record))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->getOptionLabelFromRecordUsing(fn (Attribute $record) => $this->attributeLabel($record)),
                        Toggle::make('visible_in_specs')->label('Показывать в карточке товара')->default(true),
                        Toggle::make('visible_in_compare')->label('Показывать в сравнении')->default(false),
                        Toggle::make('is_required')->label('Обязательный для категории')->default(false),
                    ])
                    ->after(function (): void {
                        $this->invalidateCategoryFiltersCache();
                    }),

            ])
            ->recordActions([
                DetachAction::make()
                    ->after(function (): void {
                        $this->invalidateCategoryFiltersCache();
                    }),
                EditAction::make()
                    ->after(function (): void {
                        $this->invalidateCategoryFiltersCache();
                    }),
            ])
            ->toolbarActions([]);
    }

    private function attributeLabel(Attribute $attribute): string
    {
        return "{$attribute->name} [ID: {$attribute->id}]";
    }

    private function invalidateCategoryFiltersCache(): void
    {
        $categoryId = (int) ($this->getOwnerRecord()?->getKey() ?? 0);

        if ($categoryId > 0) {
            FilterSchemaCache::forgetCategory($categoryId);
        }
    }
}
