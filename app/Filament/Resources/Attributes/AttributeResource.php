<?php

namespace App\Filament\Resources\Attributes;

use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Attributes\Pages\EditAttribute;
use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Filament\Resources\Attributes\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\Attributes\RelationManagers\OptionsRelationManager;
use App\Filament\Resources\Attributes\RelationManagers\ProductsUnifiedRelationManager;
use App\Filament\Resources\Attributes\Schemas\AttributeForm;
use App\Filament\Resources\Attributes\Tables\AttributesTable;
use App\Models\Attribute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class AttributeResource extends Resource
{
    protected static ?string $model = Attribute::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Фильтры';

    protected static ?string $modelLabel = 'фильтр';

    protected static ?string $pluralModelLabel = 'Фильтры';

    protected static string|UnitEnum|null $navigationGroup = 'Фильтры';

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

    public static function dataTypeOptions(): array
    {
        return [
            'text' => 'Текст',
            'number' => 'Число',
            'range' => 'Диапазон',
            'boolean' => 'Да / Нет',
        ];
    }

    public static function inputTypeOptions(): array
    {
        return [
            'select' => 'Опция (один вариант)',
            'multiselect' => 'Опции (несколько)',
            'number' => 'Число',
            'range' => 'Диапазон',
            'boolean' => 'Да / Нет',
        ];
    }

    public static function inputTypeOptionsForDataType(?string $dataType): array
    {
        return match ($dataType) {
            'number' => [
                'number' => self::inputTypeOptions()['number'],
            ],
            'range' => [
                'range' => self::inputTypeOptions()['range'],
            ],
            'boolean' => [
                'boolean' => self::inputTypeOptions()['boolean'],
            ],
            default => [
                'select' => self::inputTypeOptions()['select'],
                'multiselect' => self::inputTypeOptions()['multiselect'],
            ],
        };
    }

    public static function defaultInputTypeForDataType(?string $dataType): string
    {
        return array_key_first(self::inputTypeOptionsForDataType($dataType)) ?? 'select';
    }

    public static function normalizeTypePair(array $data): array
    {
        $dataType = (string) ($data['data_type'] ?? 'text');

        if (! array_key_exists($dataType, self::dataTypeOptions())) {
            $dataType = 'text';
        }

        $inputType = (string) ($data['input_type'] ?? '');
        if ($dataType === 'text' && $inputType === 'text') {
            $data['data_type'] = $dataType;
            $data['input_type'] = $inputType;

            unset($data['filter_ui']);

            return $data;
        }

        $allowedInputTypes = array_keys(self::inputTypeOptionsForDataType($dataType));

        if (! in_array($inputType, $allowedInputTypes, true)) {
            $inputType = self::defaultInputTypeForDataType($dataType);
        }

        $data['data_type'] = $dataType;
        $data['input_type'] = $inputType;

        unset($data['filter_ui']);

        return $data;
    }

    public static function applyUiMap(array $data): array
    {
        return self::normalizeTypePair($data);
    }
}
