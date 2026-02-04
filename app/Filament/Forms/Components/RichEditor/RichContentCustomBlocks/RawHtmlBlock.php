<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Textarea;

class RawHtmlBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'raw-html';
    }

    public static function getLabel(): string
    {
        return 'Сырой HTML';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Сырой HTML')
            ->modalDescription('Любой HTML-код, который будет выведен без изменений. Используй осторожно.')
            ->schema([
                Textarea::make('html')
                    ->label('HTML')
                    ->rows(10)
                    ->required()
                    ->autosize(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        $html = (string) ($config['html'] ?? '');

        // Покажем обрезанный текст, чтобы не рендерить всё внутри редактора
        $snippet = mb_strlen($html) > 120
            ? mb_substr($html, 0, 120).'…'
            : $html;

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.raw-html.preview', [
            'snippet' => $snippet,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        // Никакого Blade, ничего — просто отдаём то, что ввели
        return (string) ($config['html'] ?? '');
    }
}
