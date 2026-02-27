<?php

namespace App\Filament\Resources\Settings\Tables;

use App\Models\Setting;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function (string $state, Setting $record): string {
                        $translationKey = 'settings.'.$record->key;
                        $translated = __($translationKey);

                        if ($translated === $translationKey) {
                            return $record->key;
                        }

                        return $translated;
                    }),

                TextColumn::make('value')
                    ->label('Значение')
                    ->limit(60)
                    ->tooltip(fn (Setting $record): string => (string) $record->value),
            ])
            ->defaultSort('key')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
