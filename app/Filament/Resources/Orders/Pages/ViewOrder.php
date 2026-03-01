<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Просмотр заказа';
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')
                ->columns(3)
                ->schema([
                    TextEntry::make('order_number')->label('Номер')->copyable(),
                    TextEntry::make('order_date')->label('Дата')->date('d.m.Y'),
                    TextEntry::make('status')
                        ->label('Статус')
                        ->badge()
                        ->formatStateUsing(function (mixed $state): string {
                            if ($state instanceof OrderStatus) {
                                return $state->label();
                            }

                            $key = (string) $state;

                            return __('order.status.'.$key);
                        }),
                    TextEntry::make('payment_status')
                        ->label('Оплата')
                        ->badge()
                        ->formatStateUsing(function (mixed $state): string {
                            if ($state instanceof PaymentStatus) {
                                return $state->label();
                            }

                            $key = (string) $state;

                            return __('order.payment.'.$key);
                        }),
                    TextEntry::make('currency')->label('Валюта'),
                    TextEntry::make('submitted_at')->label('Отправлен')->dateTime(),
                    TextEntry::make('created_at')->label('Создан')->dateTime(),
                    TextEntry::make('updated_at')->label('Обновлён')->dateTime(),
                ]),

            Section::make('Клиент')
                ->columns(3)
                ->schema([
                    TextEntry::make('is_company')
                        ->label('Юрлицо/ИП')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет'),
                    TextEntry::make('customer_name')->label('ФИО'),
                    TextEntry::make('customer_email')->label('Email'),
                    TextEntry::make('customer_phone')->label('Телефон'),
                    TextEntry::make('company_name')->label('Компания'),
                    TextEntry::make('inn')->label('ИНН'),
                    TextEntry::make('kpp')->label('КПП'),
                ]),

            Section::make('Доставка / Самовывоз')
                ->columns(3)
                ->schema([
                    TextEntry::make('shipping_method')
                        ->label('Способ')
                        ->formatStateUsing(function (mixed $state): string {
                            if ($state instanceof ShippingMethod) {
                                return $state->label();
                            }

                            $key = (string) $state;

                            return __('order.shipping_method.'.$key);
                        }),
                    TextEntry::make('pickup_point_id')->label('ПВЗ ID'),
                    TextEntry::make('shipping_country')->label('Страна'),
                    TextEntry::make('shipping_region')->label('Регион'),
                    TextEntry::make('shipping_city')->label('Город'),
                    TextEntry::make('shipping_street')->label('Улица')->columnSpan(2),
                    TextEntry::make('shipping_house')->label('Дом'),
                    TextEntry::make('shipping_postcode')->label('Индекс'),
                    TextEntry::make('shipping_comment')->label('Комментарий')->columnSpanFull(),
                ]),

            Section::make('Суммы')
                ->columns(4)
                ->schema([
                    TextEntry::make('items_subtotal')
                        ->label('Сумма товаров')
                        ->formatStateUsing(fn (mixed $state): string => price($state)),
                    TextEntry::make('discount_total')
                        ->label('Скидка')
                        ->formatStateUsing(fn (mixed $state): string => price($state)),
                    TextEntry::make('shipping_total')
                        ->label('Доставка')
                        ->formatStateUsing(fn (mixed $state): string => price($state)),
                    TextEntry::make('grand_total')
                        ->label('Итого')
                        ->weight('bold')
                        ->formatStateUsing(fn (mixed $state): string => price($state)),
                ]),
        ]);
    }
}
