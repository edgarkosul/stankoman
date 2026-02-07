<?php

namespace App\Filament\Resources\Sliders\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SliderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Общие')
                    ->components([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('key')
                            ->label('Ключ (опционально)')
                            ->helperText('Например: home-hero')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Слайды')
                    ->components([
                        Repeater::make('slides')
                            ->label('Слайды')
                            ->schema([
                                FileUpload::make('image')
                                    ->label('Изображение')
                                    ->image()
                                    ->directory('pics')
                                    ->disk('public')
                                    ->maxSize(4096)
                                    ->required(),

                                TextInput::make('url')
                                    ->label('Ссылка')
                                    ->url()
                                    ->nullable(),

                                TextInput::make('alt')
                                    ->label('Alt-текст')
                                    ->maxLength(255)
                                    ->nullable(),
                            ])
                            ->minItems(1)
                            ->reorderable()
                            ->collapsible(),
                    ])
                    ->collapsed(false),
            ]);
    }
}
