<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Order;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordTitleAttribute('order_number')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Номер')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('order_date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Клиент')
                    ->url(
                        fn (Order $record): ?string => $record->user_id
                            ? UserResource::getUrl('edit', ['record' => $record->user_id])
                            : null
                    )
                    ->searchable(),

                SelectColumn::make('status')
                    ->label('Статус')
                    ->options(OrderStatus::options())
                    ->selectablePlaceholder(false)
                    ->sortable(),

                SelectColumn::make('payment_status')
                    ->label('Оплата')
                    ->options(PaymentStatus::options())
                    ->selectablePlaceholder(false)
                    ->sortable(),

                IconColumn::make('is_company')
                    ->label('Юр.')
                    ->boolean(),

                TextColumn::make('grand_total')
                    ->label('Итого')
                    ->sortable()
                    ->formatStateUsing(
                        fn (mixed $state, Order $record): string => number_format((float) $state, 2, ',', ' ').' '.($record->currency ?? 'RUB')
                    ),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(OrderStatus::options()),

                SelectFilter::make('payment_status')
                    ->label('Оплата')
                    ->options(PaymentStatus::options()),

                Filter::make('created_at')
                    ->label('Дата создания')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, function (Builder $builder, mixed $date): Builder {
                                return $builder->whereDate('created_at', '>=', $date);
                            })
                            ->when($data['until'] ?? null, function (Builder $builder, mixed $date): Builder {
                                return $builder->whereDate('created_at', '<=', $date);
                            });
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'С: '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'По: '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Редактировать'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
