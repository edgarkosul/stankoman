<?php

namespace App\Filament\Resources\KbArticles\Schemas;

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\PdfLinkBlock;
use App\Models\KbCategory;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Редактор статьи — тот же RichEditor, что у страниц сайта, но хранит JSON.
 *
 * JSON, а не HTML, как у страниц: индексатор статей (`KbArticleKbSource`)
 * читает документ редактора напрямую, без разбора HTML.
 *
 * Набор кастомных блоков урезан до двух. Слайдеры, галереи, карта и видео
 * базе знаний не нужны: бот читает текст, а всё вставленное блоками либо
 * не попадёт в индекс, либо попадёт мусором. Оставлены картинка и ссылка
 * на PDF — единственное, что осмысленно цитировать покупателю.
 */
class KbArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Заголовок')
                    ->helperText('Сформулируйте так, как спрашивает покупатель: «Как вернуть товар», а не «Возврат».')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Select::make('kb_category_id')
                    ->label('Раздел')
                    ->options(fn (): array => KbCategory::query()->orderBy('position')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->helperText('Для порядка в списке. На ответы бота почти не влияет.'),

                Toggle::make('is_published')
                    ->label('Опубликована')
                    ->helperText('Черновик бот не читает.')
                    ->default(false),

                TextInput::make('public_url')
                    ->label('Ссылка на страницу сайта')
                    ->url()
                    ->maxLength(512)
                    ->helperText('Если ответ есть и на сайте — бот даст эту ссылку. Если заполняете часто, подумайте: не дублирует ли статья сайт?')
                    ->columnSpanFull(),

                RichEditor::make('content')
                    ->label('Текст')
                    ->helperText('Пишите так, как ответили бы покупателю в переписке. Бот перескажет своими словами.')
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'link'],
                        ['h2', 'h3'],
                        ['blockquote', 'bulletList', 'orderedList'],
                        ['table', 'attachFiles', 'customBlocks'],
                        ['undo', 'redo'],
                    ])
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('kb')
                    ->fileAttachmentsVisibility('public')
                    ->customBlocks([
                        ImageBlock::class,
                        PdfLinkBlock::class,
                    ])
                    ->json()
                    ->required()
                    ->columnSpanFull(),
            ]);
    }
}
