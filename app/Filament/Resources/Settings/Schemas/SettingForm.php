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
                    ->visible(fn (Get $get): bool => ! in_array($get('key'), ['general.manager_emails', 'general.filament_admin_emails'], true))
                    ->dehydrated(fn (Get $get): bool => ! in_array($get('key'), ['general.manager_emails', 'general.filament_admin_emails'], true))
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
