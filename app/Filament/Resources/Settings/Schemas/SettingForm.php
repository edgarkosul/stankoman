<?php

namespace App\Filament\Resources\Settings\Schemas;

use App\Enums\SettingType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SettingForm
{
    private const EMAIL_LIST_KEYS = [
        'general.manager_emails',
        'general.filament_admin_emails',
    ];

    private const EMAIL_VALUE_KEYS = [
        'company.public_email',
        'mail.from.address',
    ];

    private const PHONE_VALUE_KEYS = [
        'company.phone',
    ];

    private const URL_VALUE_KEYS = [
        'company.site_url',
    ];

    private const TEXT_VALUE_KEYS = [
        'company.legal_name',
        'company.brand_line',
        'company.site_host',
        'company.bank.name',
        'company.bank.bik',
        'company.bank.rs',
        'company.bank.ks',
    ];

    private const TEXTAREA_VALUE_KEYS = [
        'company.legal_addr',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Тип')
                    ->disabled()
                    ->dehydrated(true)
                    ->options([
                        SettingType::String->value => 'Строка',
                        SettingType::Int->value => 'Целое число',
                        SettingType::Float->value => 'Число с плавающей точкой',
                        SettingType::Bool->value => 'Логическое значение',
                        SettingType::Json->value => 'JSON',
                    ])
                    ->required(),

                Textarea::make('value')
                    ->label('Значение')
                    ->visible(fn (Get $get): bool => ! in_array($get('key'), [
                        ...self::EMAIL_LIST_KEYS,
                        ...self::EMAIL_VALUE_KEYS,
                        ...self::PHONE_VALUE_KEYS,
                        ...self::URL_VALUE_KEYS,
                        ...self::TEXT_VALUE_KEYS,
                        ...self::TEXTAREA_VALUE_KEYS,
                    ], true))
                    ->dehydrated(fn (Get $get): bool => ! in_array($get('key'), [
                        ...self::EMAIL_LIST_KEYS,
                        ...self::EMAIL_VALUE_KEYS,
                        ...self::PHONE_VALUE_KEYS,
                        ...self::URL_VALUE_KEYS,
                        ...self::TEXT_VALUE_KEYS,
                        ...self::TEXTAREA_VALUE_KEYS,
                    ], true))
                    ->columnSpanFull(),

                TextInput::make('email_value')
                    ->label(fn (Get $get): string => match ((string) $get('key')) {
                        'mail.from.address' => 'Email отправителя писем',
                        default => 'Публичный email',
                    })
                    ->helperText(fn (Get $get): string => match ((string) $get('key')) {
                        'mail.from.address' => 'Используется как глобальный From-адрес для исходящих писем.',
                        default => 'Показывается в письмах и публичных контактных блоках сайта.',
                    })
                    ->email()
                    ->required()
                    ->visible(fn (Get $get): bool => in_array($get('key'), self::EMAIL_VALUE_KEYS, true))
                    ->dehydrated(fn (Get $get): bool => in_array($get('key'), self::EMAIL_VALUE_KEYS, true))
                    ->columnSpanFull(),

                TextInput::make('phone_value')
                    ->label('Телефон')
                    ->helperText('Используется в шапке и футере сайта, письмах и SEO-данных.')
                    ->required()
                    ->visible(fn (Get $get): bool => in_array($get('key'), self::PHONE_VALUE_KEYS, true))
                    ->dehydrated(fn (Get $get): bool => in_array($get('key'), self::PHONE_VALUE_KEYS, true))
                    ->columnSpanFull(),

                TextInput::make('site_url_value')
                    ->label('URL сайта')
                    ->helperText('Используется в письмах и публичных ссылках бренда.')
                    ->url()
                    ->required()
                    ->visible(fn (Get $get): bool => in_array($get('key'), self::URL_VALUE_KEYS, true))
                    ->dehydrated(fn (Get $get): bool => in_array($get('key'), self::URL_VALUE_KEYS, true))
                    ->columnSpanFull(),

                TextInput::make('legal_name_value')
                    ->label('Юридическое название')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.legal_name')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.legal_name')
                    ->columnSpanFull(),

                TextInput::make('brand_line_value')
                    ->label('Брендовая подпись')
                    ->helperText('Короткое имя бренда для писем и футера сайта.')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.brand_line')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.brand_line')
                    ->columnSpanFull(),

                TextInput::make('site_host_value')
                    ->label('Домен сайта')
                    ->helperText('Короткая форма домена для отображения без протокола.')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.site_host')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.site_host')
                    ->columnSpanFull(),

                TextInput::make('bank_name_value')
                    ->label('Название банка')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.bank.name')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.bank.name')
                    ->columnSpanFull(),

                TextInput::make('bank_bik_value')
                    ->label('БИК')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.bank.bik')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.bank.bik')
                    ->columnSpanFull(),

                TextInput::make('bank_rs_value')
                    ->label('Расчетный счет')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.bank.rs')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.bank.rs')
                    ->columnSpanFull(),

                TextInput::make('bank_ks_value')
                    ->label('Корреспондентский счет')
                    ->required()
                    ->visible(fn (Get $get): bool => $get('key') === 'company.bank.ks')
                    ->dehydrated(fn (Get $get): bool => $get('key') === 'company.bank.ks')
                    ->columnSpanFull(),

                Textarea::make('legal_addr_value')
                    ->label('Юридический адрес')
                    ->helperText('Используется в письмах, SEO и документах.')
                    ->required()
                    ->rows(4)
                    ->visible(fn (Get $get): bool => in_array($get('key'), self::TEXTAREA_VALUE_KEYS, true))
                    ->dehydrated(fn (Get $get): bool => in_array($get('key'), self::TEXTAREA_VALUE_KEYS, true))
                    ->columnSpanFull(),

                Repeater::make('manager_emails')
                    ->label('Email менеджеров')
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(),
                    ])
                    ->visible(fn (Get $get): bool => $get('key') === 'general.manager_emails')
                    ->columnSpanFull(),

                Repeater::make('filament_admin_emails')
                    ->label('Email админов Filament')
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(),
                    ])
                    ->visible(fn (Get $get): bool => $get('key') === 'general.filament_admin_emails')
                    ->columnSpanFull(),
            ])->columns(2);
    }
}
