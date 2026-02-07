<?php

namespace App\Filament\Resources\Sliders\Tables;

use App\Models\Slider;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slides_count')
                    ->label('Кол-во слайдов')
                    ->state(fn (Slider $record) => count($record->slides ?? [])),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()->chunkSelectedRecords(250),
            ])
            ->defaultSort('id', 'desc');
    }
}
