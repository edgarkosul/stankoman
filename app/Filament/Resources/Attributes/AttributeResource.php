<?php

namespace App\Filament\Resources\Attributes;

use UnitEnum;
use BackedEnum;
use App\Models\Attribute;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\Attributes\Pages\EditAttribute;
use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Attributes\Schemas\AttributeForm;
use App\Filament\Resources\Attributes\Tables\AttributesTable;
use App\Filament\Resources\Attributes\RelationManagers\OptionsRelationManager;
use App\Filament\Resources\Attributes\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\Attributes\RelationManagers\ProductsUnifiedRelationManager;
use App\Filament\Resources\Attributes\RelationManagers\ProductsViaValuesRelationManager;

class AttributeResource extends Resource
{
    protected static ?string $model = Attribute::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Фильтры';

    protected static ?string $modelLabel = 'фильтр';
    protected static ?string $pluralModelLabel = 'Фильтры';

    protected static string | UnitEnum | null $navigationGroup = 'Фильтры';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return AttributeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttributesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CategoriesRelationManager::class,
            ProductsUnifiedRelationManager::class,
            OptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributes::route('/'),
            'create' => CreateAttribute::route('/create'),
            'edit' => EditAttribute::route('/{record}/edit'),
        ];
    }

    public static function applyUiMap(array $data): array
    {
        $ui = $data['filter_ui'] ?? null;

        // обнулить input_type по умолчанию
        $data['input_type'] = null;

        switch ($ui) {
            case 'select':
                $data['input_type'] = 'select';
                $data['data_type']  = 'text';   // семантика не важна, храним в pivot
                break;

            case 'multiselect':
                $data['input_type'] = 'multiselect';
                $data['data_type']  = 'text';
                break;

            case 'number':
                $data['data_type']  = 'number';
                break;

            case 'range':
                $data['data_type']  = 'range';
                break;

            case 'boolean':
                $data['data_type']  = 'boolean';
                break;

            default: // 'text'
                $data['data_type']  = 'text';
                break;
        }

        unset($data['filter_ui']);
        return $data;
    }
}
