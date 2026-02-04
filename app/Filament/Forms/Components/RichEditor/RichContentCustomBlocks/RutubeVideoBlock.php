<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class RutubeVideoBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'rutube-video';
    }

    public static function getLabel(): string
    {
        return 'Видео Rutube';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Видео Rutube')
            ->modalDescription('Укажите ID видео Rutube, при необходимости ширину и выравнивание.')
            ->schema([
                TextInput::make('rutube_id')
                    ->label('Rutube ID')
                    ->helperText('Например: 1b883a32340ac3c8f33b77695879a227')
                    ->required()
                    ->maxLength(64),

                TextInput::make('width')
                    ->belowContent('Ширина видео в пикселях. Оставьте пустым для адаптивной ширины.')
                    ->label('Ширина, px')
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
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.rutube-video.preview', [
            'rutubeId' => $config['rutube_id'] ?? null,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.rutube-video.index', [
            'rutubeId' => $config['rutube_id'] ?? null,
            'width' => is_numeric($config['width'] ?? null) ? (int) $config['width'] : null,
            'alignment' => $config['alignment'] ?? 'center',
        ])->render();
    }
}
