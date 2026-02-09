<?php

namespace App\Filament\Resources\Categories;

use UnitEnum;
use BackedEnum;
use App\Models\Category;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Filament\Resources\Categories\Widgets\CategoryTreeWidget;
use App\Filament\Resources\Categories\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Categories\RelationManagers\AttributeDefsRelationManager;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-c-bars-4';
    // protected static string | UnitEnum | null $navigationGroup = 'Категории';

    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Список категорий';

    protected static ?string $modelLabel = 'категории';
    protected static ?string $pluralModelLabel = 'категории';

    // protected static bool $shouldRegisterNavigation = false;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
            AttributeDefsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
    public static function getWidgets(): array
    {
        return [
            // CategoryTreeWidget::class,
        ];
    }


    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Адрес'   => $record->slug,
        ];
    }
}
