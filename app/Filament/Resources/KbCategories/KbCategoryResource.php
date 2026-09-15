<?php

namespace App\Filament\Resources\KbCategories;

use App\Filament\Resources\KbCategories\Pages\ManageKbCategories;
use App\Models\KbCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Разделы базы знаний. Экран нарочно бедный: раздел это папка для человека,
 * на ответы бота он почти не влияет, и городить вокруг него отдельные
 * страницы создания и редактирования незачем — всё делается прямо в списке.
 */
class KbCategoryResource extends Resource
{
    protected static ?string $model = KbCategory::class;

    /*
     * Иконки у пунктов этой группы нет намеренно: значок висит на самой
     * группе «ИИ бот», и иконка пункта уронила бы сайдбар всей админки.
     */

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Разделы статей';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'раздел';

    protected static ?string $pluralModelLabel = 'Разделы статей';

    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255)
                /*
                 * Раздел — папка для человека, а не подсказка боту: поиск идёт
                 * по смыслу текста, а название раздела попадает туда лишь
                 * одним словом в крошках. Сказать об этом надо здесь, иначе
                 * разделы начинают придумывать «чтобы бот лучше искал».
                 */
                ->helperText('Для порядка в списке статей. На то, что и как отвечает бот, разделы почти не влияют.'),
            TextInput::make('position')
                ->label('Порядок')
                ->numeric()
                ->default(0)
                ->helperText('Чем меньше число, тем выше раздел в списке.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->emptyStateHeading('Разделов нет')
            ->emptyStateDescription('Разделы нужны только для порядка в списке статей. Бот ищет по смыслу текста, а не по разделам.')
            ->columns([
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('articles_count')->label('Статей')->counts('articles')->badge(),
                TextColumn::make('position')->label('Порядок')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageKbCategories::route('/'),
        ];
    }
}
