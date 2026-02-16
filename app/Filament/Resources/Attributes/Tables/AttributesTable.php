<?php

namespace App\Filament\Resources\Attributes\Tables;

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
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->searchable(),
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('data_type')->badge(),
                TextColumn::make('value_source')->badge()->label('Источник'),
                TextColumn::make('filter_ui')
                    ->badge()
                    ->label('UI')
                    ->placeholder('—'),
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
