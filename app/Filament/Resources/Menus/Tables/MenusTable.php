<?php

namespace App\Filament\Resources\Menus\Tables;

use App\Filament\Resources\Menus\MenuResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('key')->label('Ключ')->searchable()->sortable(),
                TextColumn::make('updated_at')->label('Обновлено')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('builder')
                    ->label('Конструктор')
                    ->icon('heroicon-o-bars-3-bottom-left')
                    ->url(fn ($record) => MenuResource::getUrl('builder', ['record' => $record]))
                    ->openUrlInNewTab(false),

                EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
