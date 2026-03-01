<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Основная информация')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(32)
                            ->default(null),

                        DateTimePicker::make('email_verified_at')
                            ->label('Время верификации Email')
                            ->nullable()
                            ->suffixAction(
                                Action::make('clearEmailVerifiedAt')
                                    ->icon('heroicon-o-x-mark')
                                    ->tooltip('Сбросить верификацию')
                                    ->action(function (Set $set): void {
                                        $set('email_verified_at', null);
                                    })
                            ),
                    ]),

                Section::make('Пароль и доступ')
                    ->columns(1)
                    ->schema([
                        TextInput::make('password')
                            ->label('Новый пароль')
                            ->password()
                            ->rules([Password::defaults()])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->hint('Оставьте пустым, если не нужно менять пароль.'),
                    ]),

                Section::make('Адрес доставки')
                    ->columns(1)
                    ->schema([
                        TextInput::make('shipping_country')
                            ->label('Страна')
                            ->maxLength(64)
                            ->default(null),

                        TextInput::make('shipping_region')
                            ->label('Регион')
                            ->maxLength(128)
                            ->default(null),

                        TextInput::make('shipping_city')
                            ->label('Город')
                            ->maxLength(128)
                            ->default(null),

                        TextInput::make('shipping_street')
                            ->label('Улица')
                            ->maxLength(255)
                            ->default(null),

                        TextInput::make('shipping_house')
                            ->label('Дом')
                            ->maxLength(32)
                            ->default(null),

                        TextInput::make('shipping_postcode')
                            ->label('Индекс')
                            ->maxLength(16)
                            ->default(null),
                    ]),

                Section::make('Юр. лицо')
                    ->columns(1)
                    ->schema([
                        Toggle::make('is_company')
                            ->label('Юридическое лицо')
                            ->default(false)
                            ->required(),

                        TextInput::make('company_name')
                            ->label('Наименование')
                            ->maxLength(255)
                            ->default(null),

                        TextInput::make('inn')
                            ->label('ИНН')
                            ->maxLength(12)
                            ->default(null),

                        TextInput::make('kpp')
                            ->label('КПП')
                            ->maxLength(9)
                            ->default(null),
                    ]),
            ]);
    }
}
