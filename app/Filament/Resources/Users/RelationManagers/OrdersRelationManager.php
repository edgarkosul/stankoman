<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Заказы';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordTitleAttribute('order_number')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Номер')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
                    ->searchable(),

                TextColumn::make('order_date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(function (mixed $state): string {
                        if ($state instanceof OrderStatus) {
                            return $state->label();
                        }

                        $key = (string) $state;

                        return __('order.status.'.$key);
                    }),

                TextColumn::make('payment_status')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(function (mixed $state): string {
                        if ($state instanceof PaymentStatus) {
                            return $state->label();
                        }

                        $key = (string) $state;

                        return __('order.payment.'.$key);
                    }),

                TextColumn::make('grand_total')
                    ->label('Итого')
                    ->formatStateUsing(
                        fn (mixed $state, Order $record): string => number_format((float) $state, 2, ',', ' ').' '.($record->currency ?? 'RUB')
                    ),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Просмотр')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
