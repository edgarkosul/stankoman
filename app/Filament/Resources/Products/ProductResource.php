<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\AttributeOptionsRelationManager;
use App\Filament\Resources\Products\RelationManagers\AttributeValuesRelationManager;
use App\Filament\Resources\Products\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Товары';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'Товары';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Общее количество товаров';
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Фильтры', [
                AttributeValuesRelationManager::class,
                AttributeOptionsRelationManager::class,
            ]),
            CategoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug', 'sku'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Артикул' => $record->sku,
            'Адрес' => $record->slug,
        ];
    }
}
