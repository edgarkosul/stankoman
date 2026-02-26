<?php

namespace App\Filament\Resources\Attributes\Tables;

use App\Filament\Resources\Attributes\AttributeResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AttributesTable
{
    private const EMPTY_FILTER_UI_STATE = '__empty_filter_ui__';

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->searchable(),
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('data_type')
                    ->badge()
                    ->label('Тип данных')
                    ->formatStateUsing(
                        fn (?string $state): string => AttributeResource::dataTypeOptions()[$state ?? ''] ?? (string) $state
                    ),
                TextColumn::make('value_source')
                    ->badge()
                    ->label('Источник')
                    ->formatStateUsing(
                        fn (?string $state): string => AttributeResource::valueSourceOptions()[$state ?? ''] ?? (string) $state
                    ),
                TextColumn::make('filter_ui')
                    ->badge()
                    ->label('UI')
                    ->default(self::EMPTY_FILTER_UI_STATE)
                    ->formatStateUsing(
                        fn (string $state): string => $state === self::EMPTY_FILTER_UI_STATE
                            ? 'бегунок'
                            : (AttributeResource::filterUiOptions()[$state] ?? $state)
                    ),
                IconColumn::make('is_filterable')->boolean()->label('Активный'),
                TextColumn::make('unit.symbol')->label('Ед.'),
            ])
            ->filters([
                SelectFilter::make('value_source')->options([
                    'free' => 'Свободный ввод',
                    'options' => 'Выбор из опций',
                ]),
                TernaryFilter::make('is_filterable')->label('Фильтруемые'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
