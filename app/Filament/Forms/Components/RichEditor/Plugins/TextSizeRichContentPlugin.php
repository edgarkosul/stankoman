<?php

namespace App\Filament\Forms\Components\RichEditor\Plugins;

use App\Filament\Forms\Components\RichEditor\Actions\TextSizeAction;
use App\Filament\Forms\Components\RichEditor\TipTapExtensions\TextSizeExtension;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

class TextSizeRichContentPlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [
            app(TextSizeExtension::class),
        ];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [
            FilamentAsset::getScriptSrc('rich-content-plugins/text-size'),
        ];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('textSize')
                ->label('Размер текста')
                ->icon(Heroicon::ArrowsUpDown)
                ->action(arguments: '{ size: $getEditor().getAttributes(\'textSize\')[\'data-size\'] ?? null }'),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [
            TextSizeAction::make(),
        ];
    }
}
