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

    public static function valueSourceOptions(): array
    {
        return [
            'free' => 'Свободный ввод',
            'options' => 'Выбор из опций',
        ];
    }

    public static function valueSourceOptionsForDataType(?string $dataType): array
    {
        if ($dataType === 'text') {
            return self::valueSourceOptions();
        }

        return [
            'free' => self::valueSourceOptions()['free'],
        ];
    }

    public static function defaultValueSourceForDataType(?string $dataType): string
    {
        return $dataType === 'text' ? 'options' : 'free';
    }

    public static function filterUiOptions(): array
    {
        return [
            'tiles' => 'Плитки',
            'dropdown' => 'Выпадающий список',
        ];
    }

    public static function filterUiOptionsFor(?string $dataType, ?string $valueSource): array
    {
        if ($dataType !== 'text' || $valueSource !== 'options') {
            return [];
        }

        return self::filterUiOptions();
    }

    public static function defaultFilterUiFor(?string $dataType, ?string $valueSource): ?string
    {
        $options = self::filterUiOptionsFor($dataType, $valueSource);

        if ($options === []) {
            return null;
        }

        return array_key_first($options) ?? 'tiles';
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

    public static function valueSourceFromLegacyInputType(string $inputType, string $dataType): string
    {
        if ($dataType !== 'text') {
            return 'free';
        }

        if (in_array($inputType, ['select', 'multiselect'], true)) {
            return 'options';
        }

        if ($inputType === 'text') {
            return 'free';
        }

        return self::defaultValueSourceForDataType($dataType);
    }

    public static function filterUiFromLegacyInputType(string $inputType): ?string
    {
        if ($inputType === 'select') {
            return 'dropdown';
        }

        if ($inputType === 'multiselect') {
            return 'tiles';
        }

        return null;
    }

    public static function legacyInputTypeFor(string $dataType, string $valueSource): string
    {
        return match ($dataType) {
            'number' => 'number',
            'range' => 'range',
            'boolean' => 'boolean',
            default => $valueSource === 'options' ? 'multiselect' : 'text',
        };
    }

    public static function normalizeTypePair(array $data): array
    {
        $dataType = (string) ($data['data_type'] ?? 'text');

        if (! array_key_exists($dataType, self::dataTypeOptions())) {
            $dataType = 'text';
        }

        $legacyInputType = (string) ($data['input_type'] ?? '');
        $valueSource = (string) ($data['value_source'] ?? '');
        $allowedValueSources = array_keys(self::valueSourceOptionsForDataType($dataType));

        if (! in_array($valueSource, $allowedValueSources, true)) {
            $valueSource = self::valueSourceFromLegacyInputType($legacyInputType, $dataType);
        }

        if (! in_array($valueSource, $allowedValueSources, true)) {
            $valueSource = self::defaultValueSourceForDataType($dataType);
        }

        $filterUi = (string) ($data['filter_ui'] ?? '');
        $allowedFilterUis = array_keys(self::filterUiOptionsFor($dataType, $valueSource));

        if ($allowedFilterUis === []) {
            $filterUi = null;
        } else {
            if (! in_array($filterUi, $allowedFilterUis, true)) {
                $filterUi = self::filterUiFromLegacyInputType($legacyInputType);
            }

            if (! in_array((string) $filterUi, $allowedFilterUis, true)) {
                $filterUi = self::defaultFilterUiFor($dataType, $valueSource);
            }
        }

        $data['data_type'] = $dataType;
        $data['value_source'] = $valueSource;
        $data['filter_ui'] = $filterUi;
        $data['input_type'] = self::legacyInputTypeFor($dataType, $valueSource);

        return $data;
    }

    public static function applyUiMap(array $data): array
    {
        return self::normalizeTypePair($data);
    }
}
