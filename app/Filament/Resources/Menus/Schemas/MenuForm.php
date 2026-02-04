<?php

namespace App\Filament\Resources\Menus\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Меню')->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(120),

                TextInput::make('key')
                    ->label('Ключ')
                    ->helperText('Например: primary, footer')
                    ->required()
                    ->maxLength(32)
                    ->unique(ignoreRecord: true),
            ])->columns(2),
        ]);
    }
}
