<?php

namespace App\Filament\Resources\Units\Tables;

use App\Models\Unit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('symbol')
                    ->label('Символ')
                    ->sortable(),

                TextColumn::make('dimension_label')
                    ->label('Измерение')
                    // ВАЖНО: сортируем по реальному полю dimension, а не по accessor’у
                    ->sortable(
                        query: fn (Builder $query, string $direction): Builder =>
                            $query->orderBy('dimension', $direction)
                    ),

                TextColumn::make('base_symbol')
                    ->label('Базовая единица (СИ)')
                    ->sortable(),

                TextColumn::make('si_factor')
                    ->label('Коэф. в СИ')
                    ->extraAttributes([
                        'title' => 'Коэффициент пересчёта значения в базовую единицу СИ (например, 1 л = 0.001 м³)',
                    ]),
            ])
            ->filters([
                SelectFilter::make('dimension')
                    ->label('Измерение')
                    ->options(Unit::dimensionOptions())
                    ->searchable(),

                SelectFilter::make('base_symbol')
                    ->label('Базовая СИ-единица')
                    ->options(Unit::baseSymbolFilterOptions())
                    ->searchable(),
            ])
            // если где-то раньше стояло defaultSort('dimension_label') — замени:
            ->defaultSort('dimension')
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
