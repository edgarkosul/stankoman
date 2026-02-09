<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Attribute;
use App\Models\Unit;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class AttributeValuesRelationManager extends RelationManager
{
    protected static ?string $title = 'Фильтры — свободные значения';
    protected static string $relationship = 'attributeValues';
    protected static ?string $inverseRelationship = 'product';
    protected static ?string $pluralModelLabel = 'свободные значения';
    protected static ?string $modelLabel = 'свободное значение';

    private const OPTION_INPUT_TYPES = ['select', 'multiselect'];

    /** Кэш атрибутов и единиц на время жизни менеджера. */
    protected array $unitCache = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Значение атрибута')->schema([
                Hidden::make('id')->dehydrated(false),

                // 1) Атрибут из primary-категории товара (с фоллбэком ко всем)
                Select::make('attribute_id')
                    ->label('Фильтр')
                    ->options(function ($record) {
                        $product = $this->getOwnerRecord();

                        $options = collect();
                        if ($product) {
                            $attrs = $product->getPrimaryCategoryAttributes();
                            if ($attrs && $attrs->isNotEmpty()) {
                                $attrs = $this->filterValueAttributes($attrs);

                                if ($attrs->isNotEmpty()) {
                                    $options = $this->mapAttributesWithId($attrs);
                                }
                            }
                        }

                        if ($options->isEmpty()) {
                            $options = $this->mapAttributesWithId(
                                $this->valueAttributesQuery()->orderBy('name')->get()
                            );
                        }

                        // при создании — скрыть уже использованные атрибуты (по одному PAV на атрибут)
                        if (! $record && $product) {
                            $used = $product->attributeValues()->pluck('attribute_id')->all();
                            if ($used) {
                                $options = $options->except($used);
                            }
                        }

                        // при редактировании — гарантировать наличие текущего
                        if ($record && $record->attribute_id && ! $options->has($record->attribute_id)) {
                            if (($attr = ($record->attribute ?? Attribute::find($record->attribute_id))) && ! $attr->usesOptions()) {
                                $options->put($attr->id, $this->attributeLabel($attr));
                            }
                        }

                        return $options;
                    })
                    ->getOptionLabelUsing(fn($value) => ($attr = Attribute::find($value)) ? $this->attributeLabel($attr) : null)
                    ->rule(function (Get $get) {
                        $productId = $this->getOwnerRecord()->getKey();
                        $currentId = $get('id'); // ← из Hidden('id')

                        $rule = Rule::unique('product_attribute_values', 'attribute_id')
                            ->where(fn($q) => $q->where('product_id', $productId));

                        if ($currentId) {
                            $rule->ignore($currentId, 'id'); // игнорим текущую строку
                        }

                        return $rule;
                    })
                    ->validationMessages([
                        'unique' => 'Для этого товара такой атрибут уже задан.',
                    ])
                    ->searchable()
                    ->preload()
                    ->required()
                    ->reactive(),

                // 2) TEXT
                TextInput::make('value_text')
                    ->label('Значение')
                    ->visible(fn(Get $get) => self::dataType($get) === 'text')
                    ->maxLength(65535),

                // 3) NUMBER (одно число в UI-единице; observer положит *_si)
                // 3) NUMBER (одно число в UI-единице; observer положит *_si)
                TextInput::make('value_number')
                    ->label(fn(Get $get) => $this->labelWithUnit($get, 'Значение'))
                    ->numeric()
                    ->inputMode('decimal')
                    ->step(fn(Get $get) => $this->numberStep($get))
                    ->rule(function (Get $get) {
                        return function (string $attribute, $value, $fail) use ($get) {
                            $dec = $this->numberDecimals($get);

                            if ($dec === null || $value === null || $value === '') {
                                return;
                            }

                            $raw = str_replace(',', '.', (string) $value);

                            if (! is_numeric($raw)) {
                                $fail('Значение должно быть числом.');
                                return;
                            }

                            if (str_contains($raw, '.')) {
                                $fraction = explode('.', $raw, 2)[1];
                                if (strlen($fraction) > $dec) {
                                    $fail("Значение поля :attribute не может содержать больше {$dec} цифр после запятой.");
                                }
                            }
                        };
                    })
                    ->formatStateUsing(function ($state, Get $get) {
                        // при создании — state = null
                        if ($state === null || $state === '') {
                            return $state;
                        }

                        [$baseUnit, $displayUnit] = $this->resolveUnits($get);

                        $display = $this->convertBaseToDisplay((float) $state, $baseUnit, $displayUnit);

                        // немного подчистим хвост нулей
                        $dec = $this->numberDecimals($get);
                        if ($dec !== null) {
                            // показываем строго с нужным количеством знаков
                            return number_format($display, $dec, '.', '');
                        }

                        return $display;
                    })
                    ->dehydrateStateUsing(function ($state, Get $get) {
                        if ($state === null || $state === '') {
                            return null;
                        }

                        $value = (float) str_replace(',', '.', (string) $state);

                        [$baseUnit, $displayUnit] = $this->resolveUnits($get);

                        // К моменту сохранения переведём обратно в базовый юнит атрибута
                        return $this->convertDisplayToBase($value, $baseUnit, $displayUnit);
                    })
                    ->visible(fn(Get $get) => self::dataType($get) === 'number'),


                // 4) BOOLEAN
                Toggle::make('value_boolean')
                    ->label('Нет / Да')
                    ->visible(fn(Get $get) => self::dataType($get) === 'boolean'),

                // 5) RANGE (мин/макс в UI; observer положит *_si)
                Grid::make(2)->schema([
                    TextInput::make('value_min')
                        ->label(fn(Get $get) => $this->labelWithUnit($get, 'Мин.'))
                        ->numeric()
                        ->inputMode('decimal')
                        ->step(fn(Get $get) => $this->numberStep($get))
                        ->rule(function (Get $get) {
                            return function (string $attribute, $value, $fail) use ($get) {
                                $dec = $this->numberDecimals($get);

                                if ($dec === null || $value === null || $value === '') {
                                    return;
                                }

                                $raw = str_replace(',', '.', (string) $value);

                                if (! is_numeric($raw)) {
                                    $fail('Значение должно быть числом.');
                                    return;
                                }

                                if (str_contains($raw, '.')) {
                                    $fraction = explode('.', $raw, 2)[1];
                                    if (strlen($fraction) > $dec) {
                                        $fail("Значение поля :attribute не может содержать больше {$dec} цифр после запятой.");
                                    }
                                }
                            };
                        })
                        ->formatStateUsing(function ($state, Get $get) {
                            if ($state === null || $state === '') {
                                return $state;
                            }

                            [$baseUnit, $displayUnit] = $this->resolveUnits($get);

                            $display = $this->convertBaseToDisplay((float) $state, $baseUnit, $displayUnit);

                            $dec = $this->numberDecimals($get);
                            if ($dec !== null) {
                                return number_format($display, $dec, '.', '');
                            }

                            return $display;
                        })
                        ->dehydrateStateUsing(function ($state, Get $get) {
                            if ($state === null || $state === '') {
                                return null;
                            }

                            $value = (float) str_replace(',', '.', (string) $state);
                            [$baseUnit, $displayUnit] = $this->resolveUnits($get);

                            return $this->convertDisplayToBase($value, $baseUnit, $displayUnit);
                        })
                        ->rule(function (Get $get) {
                            return function (string $attribute, $value, $fail) use ($get) {
                                $min = $value;
                                $max = $get('value_max');
                                if ($min !== null && $min !== '' && $max !== null && $max !== '' && is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                                    $fail('Минимум не может быть больше максимума.');
                                }
                            };
                        }),

                    TextInput::make('value_max')
                        ->label(fn(Get $get) => $this->labelWithUnit($get, 'Макс.'))
                        ->numeric()
                        ->inputMode('decimal')
                        ->step(fn(Get $get) => $this->numberStep($get))
                        ->rule(function (Get $get) {
                            return function (string $attribute, $value, $fail) use ($get) {
                                $dec = $this->numberDecimals($get);

                                if ($dec === null || $value === null || $value === '') {
                                    return;
                                }

                                $raw = str_replace(',', '.', (string) $value);

                                if (! is_numeric($raw)) {
                                    $fail('Значение должно быть числом.');
                                    return;
                                }

                                if (str_contains($raw, '.')) {
                                    $fraction = explode('.', $raw, 2)[1];
                                    if (strlen($fraction) > $dec) {
                                        $fail("Значение поля :attribute не может содержать больше {$dec} цифр после запятой.");
                                    }
                                }
                            };
                        })
                        ->formatStateUsing(function ($state, Get $get) {
                            if ($state === null || $state === '') {
                                return $state;
                            }

                            [$baseUnit, $displayUnit] = $this->resolveUnits($get);

                            $display = $this->convertBaseToDisplay((float) $state, $baseUnit, $displayUnit);

                            $dec = $this->numberDecimals($get);
                            if ($dec !== null) {
                                return number_format($display, $dec, '.', '');
                            }

                            return $display;
                        })
                        ->dehydrateStateUsing(function ($state, Get $get) {
                            if ($state === null || $state === '') {
                                return null;
                            }

                            $value = (float) str_replace(',', '.', (string) $state);
                            [$baseUnit, $displayUnit] = $this->resolveUnits($get);

                            return $this->convertDisplayToBase($value, $baseUnit, $displayUnit);
                        })
                        ->rule(function (Get $get) {
                            return function (string $attribute, $value, $fail) use ($get) {
                                $max = $value;
                                $min = $get('value_min');
                                if ($max !== null && $max !== '' && $min !== null && $min !== '' && is_numeric($max) && is_numeric($min) && (float) $max < (float) $min) {
                                    $fail('Максимум не может быть меньше минимума.');
                                }
                            };
                        }),
                ])->visible(fn(Get $get) => self::dataType($get) === 'range'),

            ]),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->emptyStateHeading('Нет свободных значений')
            ->emptyStateDescription('Числа, диапазоны, текст и да/нет редактируются здесь.')
            ->columns([
                TextColumn::make('attribute.id')
                    ->label('ID')
                    ->badge(),
                TextColumn::make('attribute.name')
                    ->label('Атрибут')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('display_value')
                    ->label('Значение')
                    ->state(fn($record) => $this->formatDisplayValueForCategory($record))
                    ->wrap(),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->whereHas('attribute', fn(Builder $attrQuery) => $this->applyValueAttributeFilter($attrQuery));
            })
            ->headerActions([
                CreateAction::make()->modalHeading('Добавить значение'),
            ])
            ->recordActions([
                EditAction::make()->modalHeading('Редактирование значения'),
                DeleteAction::make()->label('Удалить'),
            ])
            ->defaultSort('id', 'desc');
    }

    /* ===================== Helpers (тип и точность) ===================== */

    private function valueAttributesQuery(): Builder
    {
        return $this->applyValueAttributeFilter(Attribute::query());
    }

    private function applyValueAttributeFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('input_type')
                ->orWhereNotIn('input_type', self::OPTION_INPUT_TYPES);
        });
    }

    private function filterValueAttributes($attributes)
    {
        return collect($attributes)
            ->filter(fn(Attribute $attr) => ! $attr->usesOptions());
    }

    private function mapAttributesWithId($attributes)
    {
        return collect($attributes)
            ->mapWithKeys(fn(Attribute $attr) => [$attr->id => $this->attributeLabel($attr)]);
    }

    private function attributeLabel(Attribute $attr): string
    {
        return "{$attr->name} [ID: {$attr->id}]";
    }

    protected static function dataType(Get $get): ?string
    {
        $attrId = (int) $get('attribute_id');
        if (! $attrId) {
            return null;
        }

        static $cache = [];
        if (! array_key_exists($attrId, $cache)) {
            $cache[$attrId] = Attribute::query()->find($attrId)?->data_type;
        }

        return $cache[$attrId];
    }

    protected function numberDecimals(Get $get): ?int
    {
        $attrId = (int) $get('attribute_id');
        if (! $attrId) {
            return null;
        }

        static $cache = [];

        $product   = $this->getOwnerRecord();
        $productId = $product?->getKey() ?? 0;
        $cacheKey  = "dec:{$productId}:{$attrId}";

        if (! array_key_exists($cacheKey, $cache)) {
            $dec = null;

            // 1) пробуем взять из pivot category_attribute
            if ($pivot = $this->getPrimaryCategoryPivotForAttr($attrId)) {
                if ($pivot->number_decimals !== null) {
                    $dec = (int) $pivot->number_decimals;
                }
            }

            // 2) фоллбек на настройку самого атрибута
            if ($dec === null) {
                $dec = Attribute::with('unit')->find($attrId)?->numberDecimals();
            }

            $cache[$cacheKey] = $dec;
        }

        return $cache[$cacheKey];
    }

    protected function numberStep(Get $get): string
    {
        $attrId = (int) $get('attribute_id');
        if (! $attrId) {
            return 'any';
        }

        static $cache = [];

        $product   = $this->getOwnerRecord();
        $productId = $product?->getKey() ?? 0;
        $cacheKey  = "step:{$productId}:{$attrId}";

        if (! array_key_exists($cacheKey, $cache)) {
            $step = null;

            // 1) из pivot category_attribute
            if ($pivot = $this->getPrimaryCategoryPivotForAttr($attrId)) {
                if ($pivot->number_step !== null) {
                    $step = (string) $pivot->number_step;
                }
            }

            // 2) фоллбек на атрибут
            if ($step === null) {
                $step = Attribute::with('unit')->find($attrId)?->numberStep() ?? 'any';
            }

            $cache[$cacheKey] = $step;
        }

        return $cache[$cacheKey];
    }


    /* ===================== Helpers (единицы и конвертация) ===================== */

    /**
     * Вернёт [Attribute|null, Unit|null $baseUnit, Unit|null $displayUnit]
     * с учётом основной категории товара.
     */
    protected function resolveUnitsForAttribute(int $attrId): array
    {
        if (! $attrId) {
            return [null, null, null];
        }

        if (! array_key_exists($attrId, $this->unitCache)) {
            $attribute = Attribute::with('unit')->find($attrId);
            $baseUnit  = $attribute?->unit;
            $displayUnit = $baseUnit;

            $product = $this->getOwnerRecord();

            if ($product && method_exists($product, 'getPrimaryCategoryAttributes')) {
                $attrs = $product->getPrimaryCategoryAttributes();
                if ($attrs && $attrs->isNotEmpty()) {
                    $attrInCat = $attrs->firstWhere('id', $attrId);
                    $displayUnitId = $attrInCat?->pivot?->display_unit_id;

                    if ($displayUnitId) {
                        $displayUnit = Unit::find($displayUnitId) ?? $displayUnit;
                    }
                }
            }

            $this->unitCache[$attrId] = [$attribute, $baseUnit, $displayUnit];
        }

        return $this->unitCache[$attrId];
    }
    /**
     * Pivot category_attribute для primary-категории текущего товара и заданного атрибута.
     */
    protected function getPrimaryCategoryPivotForAttr(int $attrId): ?\App\Models\CategoryAttribute
    {
        $product = $this->getOwnerRecord();

        if (! $product || ! method_exists($product, 'getPrimaryCategoryAttributes')) {
            return null;
        }

        $attrs = $product->getPrimaryCategoryAttributes();
        $attr  = $attrs?->firstWhere('id', $attrId);

        return $attr?->pivot;
    }


    protected function unitSymbol(Get $get): ?string
    {
        $attrId = (int) $get('attribute_id');
        if (! $attrId) {
            return null;
        }

        [,, $displayUnit] = $this->resolveUnitsForAttribute($attrId);

        return $displayUnit?->symbol;
    }

    /* ===================== Helpers ===================== */

    /**
     * Находим базовую и display-единицу для выбранного атрибута
     * (display берём из pivot primary-категории, если есть).
     *
     * @return array{0:?Unit,1:?Unit} [baseUnit, displayUnit]
     */
    protected function resolveUnits(Get $get): array
    {
        $attrId = (int) $get('attribute_id');
        if (! $attrId) {
            return [null, null];
        }

        static $cache = [];

        $product   = $this->getOwnerRecord();
        $productId = $product?->getKey() ?? 0;
        $key       = $productId . ':' . $attrId;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        // Базовый юнит атрибута
        $attribute = Attribute::with('unit')->find($attrId);
        $baseUnit  = $attribute?->unit;

        // Display-юнит из primary-категории (если есть)
        $displayUnit = null;

        if ($product && method_exists($product, 'getPrimaryCategoryAttributes')) {
            $attrFromPrimary = $product->getPrimaryCategoryAttributes()
                ->firstWhere('id', $attrId);

            if ($attrFromPrimary && $attrFromPrimary->pivot?->display_unit_id) {
                $displayUnit = Unit::find($attrFromPrimary->pivot->display_unit_id);
            }
        }

        // если display не задан — используем базовый
        if (! $displayUnit) {
            $displayUnit = $baseUnit;
        }

        return $cache[$key] = [$baseUnit, $displayUnit];
    }

    /**
     * base-value (в unit атрибута) → display-value (юнит категории).
     */
    protected function convertBaseToDisplay(float $value, ?Unit $baseUnit, ?Unit $displayUnit): float
    {
        if (! $baseUnit || ! $displayUnit) {
            return $value;
        }

        // если юниты совпадают — ничего не делаем
        if ($baseUnit->id === $displayUnit->id) {
            return $value;
        }

        if (! $baseUnit->si_factor || ! $displayUnit->si_factor) {
            return $value;
        }

        // base → SI
        $si = $value * $baseUnit->si_factor + $baseUnit->si_offset;

        // SI → display
        return ($si - $displayUnit->si_offset) / $displayUnit->si_factor;
    }

    /**
     * display-value (юнит категории) → base-value (юнит атрибута).
     */
    protected function convertDisplayToBase(float $value, ?Unit $baseUnit, ?Unit $displayUnit): float
    {
        if (! $baseUnit || ! $displayUnit) {
            return $value;
        }

        if ($baseUnit->id === $displayUnit->id) {
            return $value;
        }

        if (! $baseUnit->si_factor || ! $displayUnit->si_factor) {
            return $value;
        }

        // display → SI
        $si = $value * $displayUnit->si_factor + $displayUnit->si_offset;

        // SI → base
        return ($si - $baseUnit->si_offset) / $baseUnit->si_factor;
    }

    /** Лейбл с учётом display-юнита категории. */
    protected function labelWithUnit(Get $get, string $base): string
    {
        [, $displayUnit] = $this->resolveUnits($get);
        $symbol = $displayUnit?->symbol;

        return $symbol ? "$base, $symbol" : $base;
    }


    /**
     * При сохранении: из единицы категории (display_unit_id) → в базовую unit_id.
     * Плюс чистим лишние поля по data_type.
     */
    protected function normalizeUnitsBeforeSave(array $data): array
    {
        $attrId = isset($data['attribute_id']) ? (int) $data['attribute_id'] : 0;

        if (! $attrId) {
            return $data;
        }

        [$attribute, $baseUnit, $displayUnit] = $this->resolveUnitsForAttribute($attrId);

        if (! $attribute) {
            return $data;
        }

        if (
            ! in_array($attribute->data_type, ['number', 'range'], true) ||
            ! $baseUnit ||
            ! $displayUnit ||
            $baseUnit->id === $displayUnit->id
        ) {
            // единицы совпадают — только обрезаем лишние поля
            return static::pruneByDataType($data, $attribute->data_type);
        }

        $convert = function ($raw) use ($baseUnit, $displayUnit) {
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                return $raw;
            }

            $value = (float) $raw;

            // display -> SI
            $si = $value * $displayUnit->si_factor + $displayUnit->si_offset;

            // SI -> base
            $base = ($si - $baseUnit->si_offset) / $baseUnit->si_factor;

            return $base;
        };

        if ($attribute->data_type === 'number') {
            if (array_key_exists('value_number', $data)) {
                $data['value_number'] = $convert($data['value_number']);
            }
        } else { // range
            foreach (['value_min', 'value_max'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = $convert($data[$field]);
                }
            }
        }

        return static::pruneByDataType($data, $attribute->data_type);
    }

    /* ===================== Нормализация по типу ===================== */

    /**
     * Только чистим лишние поля по data_type.
     * Квантизацию и *_si делает Observer на PAV.
     */
    protected static function pruneByDataType(array $data, ?string $type = null): array
    {
        if ($type === null && ! empty($data['attribute_id'])) {
            $type = Attribute::query()->find((int) $data['attribute_id'])?->data_type;
        }

        $keep = [
            'text'    => ['value_text'],
            'number'  => ['value_number'],
            'boolean' => ['value_boolean'],
            'range'   => ['value_min', 'value_max'],
        ][$type] ?? [];

        foreach (['value_text', 'value_number', 'value_boolean', 'value_min', 'value_max'] as $field) {
            if (! in_array($field, $keep, true)) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return static::pruneByDataType($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return static::pruneByDataType($data);
    }
    /**
     * Отформатировать значение PAV с учётом display_unit_id для primary-категории товара.
     * Числа/диапазоны конвертим через наши base↔display юниты,
     * всё остальное оставляем как есть (через accessor display_value).
     */
    protected function formatDisplayValueForCategory($record): ?string
    {
        /** @var \App\Models\ProductAttributeValue $record */
        $attribute = $record->attribute;

        if (! $attribute) {
            return $record->display_value;
        }

        // Для текстов и булевых – пусть работает существующий accessor.
        if (! in_array($attribute->data_type, ['number', 'range'], true)) {
            return $record->display_value;
        }

        // Находим базовый и display-юнит так же, как в форме
        [$attr, $baseUnit, $displayUnit] = $this->resolveUnitsForAttribute($attribute->id);

        // если нет юнитов — фоллбек
        if (! $baseUnit || ! $displayUnit) {
            return $record->display_value;
        }

        $suffix = $displayUnit->symbol ? ' ' . $displayUnit->symbol : '';
        $dec    = $attr?->numberDecimals();

        $fmt = function (?float $value) use ($attr, $dec) {
            if ($value === null) {
                return null;
            }

            // сначала даём атрибуту "квантизировать" по правилам (round/floor/ceil)
            if (method_exists($attr, 'quantize')) {
                $value = $attr->quantize($value);
            }

            if ($dec === null) {
                return (string) $value;
            }

            $str = number_format($value, $dec, '.', '');
            if ($dec > 0 && str_contains($str, '.')) {
                $str = rtrim(rtrim($str, '0'), '.');
            }

            return $str;
        };

        if ($attribute->data_type === 'number') {
            $raw = $record->value_number;
            if ($raw === null) {
                return null;
            }

            // value_number хранится в базовой единице атрибута → переводим в display
            $display = $this->convertBaseToDisplay((float) $raw, $baseUnit, $displayUnit);

            $str = $fmt($display);

            return $str !== null ? $str . $suffix : null;
        }

        if ($attribute->data_type === 'range') {
            $minRaw = $record->value_min;
            $maxRaw = $record->value_max;

            if ($minRaw === null && $maxRaw === null) {
                return null;
            }

            $minDisp = $minRaw !== null
                ? $this->convertBaseToDisplay((float) $minRaw, $baseUnit, $displayUnit)
                : null;

            $maxDisp = $maxRaw !== null
                ? $this->convertBaseToDisplay((float) $maxRaw, $baseUnit, $displayUnit)
                : null;

            $minStr = $fmt($minDisp);
            $maxStr = $fmt($maxDisp);

            if ($minStr !== null && $maxStr !== null) {
                return $minStr . '—' . $maxStr . $suffix;
            }

            if ($minStr !== null) {
                return '≥ ' . $minStr . $suffix;
            }

            if ($maxStr !== null) {
                return '≤ ' . $maxStr . $suffix;
            }

            return null;
        }

        // На всякий случай — фоллбек
        return $record->display_value;
    }
}
