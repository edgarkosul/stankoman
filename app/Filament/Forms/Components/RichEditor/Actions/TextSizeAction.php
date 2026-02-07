<?php

namespace App\Filament\Forms\Components\RichEditor\Actions;

use App\Filament\Forms\Components\RichEditor\TextSizeOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;

class TextSizeAction
{
    public static function make(): Action
    {
        return Action::make('textSize')
            ->label('Размер текста')
            ->modalHeading('Размер текста')
            ->modalWidth(Width::Small)
            ->fillForm(fn (array $arguments): ?array => filled($arguments['size'] ?? null) ? [
                'size' => TextSizeOptions::normalize($arguments['size'] ?? null),
            ] : null)
            ->schema([
                Select::make('size')
                    ->label('Размер')
                    ->options(TextSizeOptions::options())
                    ->native(false)
                    ->placeholder('Обычный'),
            ])
            ->action(function (array $arguments, array $data, RichEditor $component): void {
                $isSingleCharacterSelection = ($arguments['editorSelection']['head'] ?? null) === ($arguments['editorSelection']['anchor'] ?? null);
                $size = TextSizeOptions::normalize($data['size'] ?? null);

                if (! $size) {
                    $component->runCommands(
                        [
                            ...($isSingleCharacterSelection ? [EditorCommand::make(
                                'extendMarkRange',
                                arguments: ['textSize'],
                            )] : []),
                            EditorCommand::make('unsetTextSize'),
                        ],
                        editorSelection: $arguments['editorSelection'],
                    );

                    return;
                }

                $component->runCommands(
                    [
                        ...($isSingleCharacterSelection ? [EditorCommand::make(
                            'extendMarkRange',
                            arguments: ['textSize'],
                        )] : []),
                        EditorCommand::make(
                            'setTextSize',
                            arguments: [[
                                'size' => $size,
                            ]],
                        ),
                    ],
                    editorSelection: $arguments['editorSelection'],
                );
            });
    }
}
