<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;

class ImageBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'image';
    }

    public static function getLabel(): string
    {
        return 'Изображение';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Изображение')
            ->modalDescription('Загрузите изображение. Для вывода используются WebP-деривативы при наличии.')
            ->schema([
                FileUpload::make('file')
                    ->label('Изображение')
                    ->image()
                    ->directory('pics')
                    ->disk('public')
                    ->maxSize(4096)
                    ->required(),
                TextInput::make('alt')
                    ->label('Alt-текст (по желанию)')
                    ->maxLength(255),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.image.preview', [
            'path' => $config['file'] ?? null,
            'alt' => $config['alt'] ?? '',
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.image.index', [
            'path' => $config['file'] ?? null,
            'alt' => $config['alt'] ?? '',
        ])->render();
    }
}
