<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Страница')->components([
                TextInput::make('title')
                    ->label('Заголовок')
                    ->required()
                    ->maxLength(200)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, string $operation) {
                        // Автогенерация slug только при создании
                        if ($operation !== 'create') {
                            return;
                        }
                        $set('slug', Str::slug($state ?? ''));
                    }),

                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(200)
                    ->unique(ignoreRecord: true)
                    ->helperText('URL будет: /page/{slug}'),

                Toggle::make('is_published')
                    ->label('Опубликовано')
                    ->live()
                    ->afterStateUpdated(function (bool $state, Set $set) {
                        $set('published_at', $state ? now() : null);
                    }),

                DateTimePicker::make('published_at')
                    ->label('Дата публикации')
                    ->seconds(false),

                RichEditor::make('content')
                    ->label('Контент')
                    ->columnSpanFull()
                    ->tools([
                        RichEditorTool::make('clearContent')
                            ->label('Очистить')
                            ->icon(Heroicon::Trash)
                            ->activeStyling(false)
                            ->jsHandler("confirm('Очистить описание?') && ".'$getEditor'.'()?.chain().focus().clearContent().run()'),
                    ])
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'textColor', 'strike', 'subscript', 'superscript', 'link'],
                        ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                        ['table', 'attachFiles', 'customBlocks'],
                        ['undo', 'redo'],
                        ['horizontalRule', 'grid', 'gridDelete'],
                    ])
                    ->enableToolbarButtons([['clearContent']])
                    // если хочешь картинки из редактора:
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('pages')
                    // опционально:
                    ->resizableImages()
                    ->customBlocks([
                        ImageBlock::class,
                        ImageGalleryBlock::class,
                        RutubeVideoBlock::class,
                        YoutubeVideoBlock::class,
                        RawHtmlBlock::class,
                    ])
                    ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('pics')->fileAttachmentsVisibility('public'),

            ])->columns(2),

            Section::make('SEO')->collapsible()->components([
                TextInput::make('meta_title')->label('Meta title')->maxLength(200),
                Textarea::make('meta_description')->label('Meta description')->maxLength(300)->rows(3),
            ])->columns(2),
        ])->columns(1);
    }

    public static function headerActions(): array
    {
        return [
            Action::make('openPage')
                ->label('Открыть страницу')
                ->icon(Heroicon::ArrowTopRightOnSquare)
                ->url(fn ($record) => route('page.show', $record, absolute: false), shouldOpenInNewTab: true)
                ->hidden(fn ($record) => blank($record?->slug)),
        ];
    }
}
