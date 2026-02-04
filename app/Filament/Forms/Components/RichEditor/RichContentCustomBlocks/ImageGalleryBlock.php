<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;

class ImageGalleryBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'image_gallery';
    }

    public static function getLabel(): string
    {
        return 'Галлерея изображений';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Галерея изображений')
            ->modalDescription('Добавьте одно или несколько изображений для галереи.')
            ->schema([
                Repeater::make('images')
                    ->label('Изображения')
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
                    ])
                    ->minItems(1)
                    ->columns(1)
                    ->collapsible()
                    ->reorderable(),
            ]);
    }

    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.image-gallery.preview', [
            'images' => $config['images'] ?? [],
        ])->render();
    }

    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.image-gallery.index', [
            'images' => $config['images'] ?? [],
        ])->render();
    }
}
