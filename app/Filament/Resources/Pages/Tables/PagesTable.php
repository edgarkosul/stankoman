<?php

namespace App\Filament\Resources\Pages\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Заголовок')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->copyable()->searchable(),
                IconColumn::make('is_published')->label('Опубликовано')->boolean()->sortable(),
                TextColumn::make('updated_at')->label('Обновлено')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
