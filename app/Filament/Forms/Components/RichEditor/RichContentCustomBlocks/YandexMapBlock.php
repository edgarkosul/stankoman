<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class YandexMapBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'yandex-map';
    }

    public static function getLabel(): string
    {
        return 'Карта (Яндекс)';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Карта Яндекс')
            ->modalDescription('Вставьте URL из src iframe виджета. При необходимости задайте ширину, высоту и выравнивание.')
            ->schema([
                TextInput::make('map_url')
                    ->label('URL виджета (iframe src)')
                    ->helperText('Пример: https://yandex.ru/map-widget/v1/?lang=ru_RU&scroll=true&source=constructor-api&um=constructor%3A...')
                    ->required()
                    ->url()
                    ->maxLength(2000),

                TextInput::make('width')
                    ->belowContent('Ширина в пикселях. Оставьте пустым для адаптивной ширины.')
                    ->label('Ширина, px')
                    ->numeric()
                    ->nullable()
                    ->minValue(1),

                TextInput::make('height')
                    ->belowContent('Высота в пикселях. Оставьте пустым для значения по умолчанию.')
                    ->label('Высота, px')
                    ->numeric()
                    ->nullable()
                    ->minValue(1),

                Select::make('alignment')
                    ->label('Выравнивание')
                    ->options([
                        'left' => 'По левому краю',
                        'center' => 'По центру',
                    ])
                    ->default('center')
                    ->required(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.yandex-map.preview', [
            'mapUrl' => $config['map_url'] ?? null,
            'width' => $config['width'] ?? null,
            'height' => $config['height'] ?? null,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.yandex-map.index', [
            'mapUrl' => $config['map_url'] ?? null,
            'width' => is_numeric($config['width'] ?? null) ? (int) $config['width'] : null,
            'height' => is_numeric($config['height'] ?? null) ? (int) $config['height'] : null,
            'alignment' => $config['alignment'] ?? 'center',
        ])->render();
    }
}
