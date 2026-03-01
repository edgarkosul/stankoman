<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label('Пользователь')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->default(null)
                        ->columnSpanFull(),

                    TextInput::make('order_number')
                        ->label('Номер заказа')
                        ->disabled(),

                    DateTimePicker::make('submitted_at')
                        ->label('Отправлен')
                        ->displayFormat('d F Y G:i')
                        ->seconds(false)
                        ->native(false),

                    Select::make('status')
                        ->label('Статус')
                        ->native(false)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->options(OrderStatus::options()),

                    Select::make('payment_status')
                        ->label('Статус оплаты')
                        ->native(false)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->options(PaymentStatus::options()),

                    TextInput::make('payment_method')
                        ->label('Платёжный метод')
                        ->maxLength(32),
                ]),

            Section::make('Клиент')
                ->columns(2)
                ->schema([
                    TextInput::make('customer_name')
                        ->label('ФИО')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),

                    TextInput::make('customer_phone')
                        ->label('Телефон')
                        ->tel()
                        ->required()
                        ->maxLength(32),

                    Toggle::make('is_company')
                        ->label('Юрлицо / ИП')
                        ->inline(false)
                        ->live(),

                    Fieldset::make('Организация')
                        ->visible(fn (callable $get): bool => (bool) $get('is_company'))
                        ->schema([
                            TextInput::make('company_name')
                                ->label('Компания')
                                ->maxLength(255)
                                ->columnSpanFull(),

                            TextInput::make('inn')
                                ->label('ИНН')
                                ->maxLength(12),

                            TextInput::make('kpp')
                                ->label('КПП')
                                ->maxLength(9),
                        ])
                        ->columnSpan(2),
                ]),

            Section::make('Доставка / Самовывоз')
                ->columns(3)
                ->schema([
                    Select::make('shipping_method')
                        ->label('Способ')
                        ->native(false)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->options(ShippingMethod::options())
                        ->columnSpanFull(),

                    TextInput::make('pickup_point_id')
                        ->label('ПВЗ ID')
                        ->maxLength(64),

                    TextInput::make('shipping_country')
                        ->label('Страна')
                        ->maxLength(64),

                    TextInput::make('shipping_region')
                        ->label('Регион')
                        ->maxLength(128),

                    TextInput::make('shipping_city')
                        ->label('Город')
                        ->maxLength(128),

                    TextInput::make('shipping_street')
                        ->label('Улица')
                        ->maxLength(255)
                        ->columnSpan(2),

                    TextInput::make('shipping_house')
                        ->label('Дом')
                        ->maxLength(32),

                    TextInput::make('shipping_postcode')
                        ->label('Индекс')
                        ->maxLength(16),

                    Textarea::make('shipping_comment')
                        ->label('Комментарий')
                        ->columnSpanFull(),
                ]),

            Section::make('Суммы')
                ->columns(['default' => 4, 'lg' => 2])
                ->schema([
                    TextInput::make('items_subtotal')
                        ->label('Сумма товаров')
                        ->numeric()
                        ->default(0)
                        ->suffix('₽')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, callable $set, callable $get): void {
                            self::recalculateGrandTotal($get, $set);
                        }),

                    TextInput::make('discount_total')
                        ->label('Скидка')
                        ->numeric()
                        ->default(0)
                        ->suffix('₽')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, callable $set, callable $get): void {
                            self::recalculateGrandTotal($get, $set);
                        }),

                    TextInput::make('shipping_total')
                        ->label('Доставка')
                        ->numeric()
                        ->default(0)
                        ->suffix('₽')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, callable $set, callable $get): void {
                            self::recalculateGrandTotal($get, $set);
                        }),

                    TextInput::make('grand_total')
                        ->label('Итого')
                        ->numeric()
                        ->default(0)
                        ->suffix('₽')
                        ->disabled()
                        ->dehydrated(true),
                ]),
        ]);
    }

    protected static function recalculateGrandTotal(callable $get, callable $set): void
    {
        $items = (float) ($get('items_subtotal') ?? 0);
        $discount = (float) ($get('discount_total') ?? 0);
        $shipping = (float) ($get('shipping_total') ?? 0);

        $set('grand_total', max(0, $items - $discount + $shipping));
    }
}
