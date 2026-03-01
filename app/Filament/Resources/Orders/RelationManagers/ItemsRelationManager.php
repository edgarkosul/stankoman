<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use App\Models\OrderItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Позиции заказа';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                ImageColumn::make('product.image')
                    ->label('Фото')
                    ->imageSize(24)
                    ->state(function (OrderItem $record): ?string {
                        $path = $record->product?->image ?? $record->thumbnail_url;

                        if (! is_string($path) || trim($path) === '') {
                            return null;
                        }

                        $path = trim($path);

                        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
                            return $path;
                        }

                        return asset(ltrim($path, '/'));
                    })
                    ->default(null),

                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Наименование')
                    ->url(fn (OrderItem $record): ?string => $record->product ? ProductResource::getUrl('edit', ['record' => $record->product]) : null)
                    ->openUrlInNewTab()
                    ->wrap()
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('Кол-во')
                    ->alignRight()
                    ->sortable()
                    ->summarize(Sum::make()->label('Всего')),

                TextColumn::make('price_amount')
                    ->label('Цена')
                    ->alignRight()
                    ->sortable()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 0, '', ' ').' ₽'),

                TextColumn::make('total_amount')
                    ->label('Сумма')
                    ->alignRight()
                    ->sortable()
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 0, '', ' ').' ₽')
                    ->summarize(Sum::make()->label('Сумма')),
            ])
            ->recordActions([
                Action::make('open_public')
                    ->label('')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (OrderItem $record): bool => $record->product !== null)
                    ->url(fn (OrderItem $record): ?string => $record->product ? route('product.show', $record->product) : null)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Пока нет позиций')
            ->emptyStateDescription('Этот заказ не содержит ни одной позиции.');
    }
}
