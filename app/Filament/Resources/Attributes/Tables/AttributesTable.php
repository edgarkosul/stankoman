<?php

namespace App\Filament\Resources\Attributes\Tables;

use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;

class AttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->searchable(),
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('input_type')->badge(),
                TextColumn::make('data_type')->badge(),
                IconColumn::make('is_filterable')->boolean()->label('Активный'),
                TextColumn::make('unit.symbol')->label('Ед.'),
            ])
            ->filters([
                SelectFilter::make('input_type')->options([
                    'text' => 'text',
                    'number' => 'number',
                    'boolean' => 'boolean',
                    'select' => 'select',
                    'multiselect' => 'multiselect',
                    'range' => 'range',
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
