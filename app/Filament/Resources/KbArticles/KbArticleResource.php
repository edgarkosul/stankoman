<?php

namespace App\Filament\Resources\KbArticles;

use App\Filament\Resources\KbArticles\Pages\CreateKbArticle;
use App\Filament\Resources\KbArticles\Pages\EditKbArticle;
use App\Filament\Resources\KbArticles\Pages\ListKbArticles;
use App\Filament\Resources\KbArticles\Schemas\KbArticleForm;
use App\Filament\Resources\KbArticles\Tables\KbArticlesTable;
use App\Models\KbArticle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Статьи для бота — ответы, которых нет на страницах сайта.
 *
 * Живут в «ИИ бот», а не в «Контенте», хотя редактор похож на страничный:
 * страницу правят, чтобы её прочитал покупатель, а статью — чтобы бот
 * перестал отвечать неправильно. Разбирать эти правки приходят от диалога,
 * и всё, что для этого нужно, должно лежать в одном разделе меню.
 */
class KbArticleResource extends Resource
{
    protected static ?string $model = KbArticle::class;

    /*
     * Иконки у пунктов этой группы нет намеренно: значок висит на самой
     * группе «ИИ бот», и иконка пункта уронила бы сайдбар всей админки.
     */

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationLabel = 'Статьи для бота';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'статью для бота';

    protected static ?string $pluralModelLabel = 'Статьи для бота';

    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    public static function form(Schema $schema): Schema
    {
        return KbArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KbArticlesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKbArticles::route('/'),
            'create' => CreateKbArticle::route('/create'),
            'edit' => EditKbArticle::route('/{record}/edit'),
        ];
    }
}
