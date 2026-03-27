<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Основное')
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->schema([
                        Select::make('user_id')
                            ->label('Пользователь')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Без привязки к заказчику')
                            ->suffixAction(
                                Action::make('edit_user')
                                    ->icon('heroicon-o-user')
                                    ->tooltip('Открыть заказчика')
                                    ->url(fn (Get $get): ?string => filled($get('user_id'))
                                        ? UserResource::getUrl('edit', ['record' => $get('user_id')])
                                        : null)
                                    ->visible(fn (Get $get): bool => filled($get('user_id')))
                            )
                            ->default(null)
                            ->columnSpanFull(),

                        TextInput::make('order_number')
                            ->label('Номер заказа')
                            ->disabled(),

                        TextInput::make('order_date')
                            ->label('Дата заказа')
                            ->disabled()
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatDate($state)),

                        DateTimePicker::make('submitted_at')
                            ->label('Отправлен')
                            ->displayFormat('d.m.Y H:i')
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

                        TextInput::make('currency')
                            ->label('Валюта')
                            ->disabled(),
                    ]),

                Section::make('Клиент')
                    ->columns(1)
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
                            ->columnSpanFull(),
                    ]),

                Section::make('Доставка / Самовывоз')
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
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
                            ->columnSpanFull(),

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
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
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

                Section::make('Служебное')
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->schema([
                        TextInput::make('created_at')
                            ->label('Создан')
                            ->disabled()
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),

                        TextInput::make('updated_at')
                            ->label('Обновлён')
                            ->disabled()
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatDateTime($state)),
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

    protected static function formatDate(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        return Carbon::parse($state)->format('d.m.Y');
    }

    protected static function formatDateTime(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        return Carbon::parse($state)->format('d.m.Y H:i:s');
    }
}
