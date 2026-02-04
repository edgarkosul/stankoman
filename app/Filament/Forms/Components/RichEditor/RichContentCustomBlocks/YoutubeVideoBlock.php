<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;

class YoutubeVideoBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'youtube-video';
    }

    public static function getLabel(): string
    {
        return 'Видео YouTube';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Видео YouTube')
            ->modalDescription('Укажите ID видео YouTube (часть после v= или после youtu.be/) и при необходимости ширину.')
            ->schema([
                TextInput::make('video_id')
                    ->label('YouTube video ID')
                    ->helperText('Например: M7lc1UVf-VE')
                    ->required()
                    ->maxLength(64),

                TextInput::make('width')
                    ->label('Ширина, px')
                    ->numeric()
                    ->nullable()
                    ->minValue(1),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.youtube-video.preview', [
            'videoId' => $config['video_id'] ?? null,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.youtube-video.index', [
            'videoId' => $config['video_id'] ?? null,
            'width' => is_numeric($config['width'] ?? null) ? (int) $config['width'] : null,
        ])->render();
    }
}
