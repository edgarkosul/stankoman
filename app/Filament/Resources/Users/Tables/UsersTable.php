<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),

                TextColumn::make('shipping_city')
                    ->label('Город')
                    ->searchable(),

                TextColumn::make('shipping_street')
                    ->label('Улица')
                    ->searchable(),

                TextColumn::make('shipping_house')
                    ->label('Дом')
                    ->searchable(),

                TextColumn::make('shipping_postcode')
                    ->label('Индекс')
                    ->searchable(),

                IconColumn::make('is_company')
                    ->label('Юр. лицо')
                    ->boolean(),

                TextColumn::make('company_name')
                    ->label('Наименование')
                    ->searchable(),

                TextColumn::make('inn')
                    ->label('ИНН')
                    ->searchable(),

                TextColumn::make('kpp')
                    ->label('КПП')
                    ->searchable(),

                IconColumn::make('email_verified_at')
                    ->label('Email подтверждён')
                    ->state(fn ($record): bool => filled($record->email_verified_at))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
