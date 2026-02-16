<?php

namespace App\Filament\Resources\Attributes\RelationManagers;

use App\Models\AttributeOption;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class ProductsUnifiedRelationManager extends RelationManager
{
    protected static string $relationship = 'productLinks';

    protected static ?string $title = 'Товары использующие этот фильтр';

    public static function canViewForRecord(Model $ownerRecord, string $page): bool
    {

        if ($ownerRecord->relationLoaded('categories')) {
            return $ownerRecord->categories->isNotEmpty();
        }

        return $ownerRecord->categories()->exists();
    }

    #[On('attribute-updated')]
    public function onAttributeUpdated(): void
    {
        $this->getOwnerRecord()->refresh();
        $this->dispatch('$refresh');
    }

    public function table(Table $table): Table
    {
        $attr = $this->getOwnerRecord();
        $type = $attr?->usesOptions()
            ? 'options'
            : ($attr?->data_type ?? 'text');
        $unit = $this->getOwnerRecord()->unit?->symbol;
        $fmt = static function (?float $n): ?string {
            if ($n === null) {
                return null;
            }
            // всегда получаем десятичную строку
            $s = number_format((float) $n, 12, '.', '');
            // убираем только хвостовые нули после точки и саму точку, если она последняя
            $s = rtrim(rtrim($s, '0'), '.');

            // защита от "-0"
            return $s === '-0' ? '0' : $s;
        };

        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('product_id', 'asc')
            ->columns([
                TextColumn::make('product.id')->label('ID')->sortable(),
                TextColumn::make('product.name')
                    ->url(fn ($record) => \App\Filament\Resources\Products\ProductResource::getUrl('edit', [
                        'record' => $record->product?->slug,
                    ]))
                    ->openUrlInNewTab()
                    ->label('Товар')
                    ->searchable()
                    ->limit(80),

                // значения из VIEW:
                TextColumn::make('value_text')
                    ->label('Текст')
                    ->toggleable()
                    ->visible(fn () => $type === 'text'),
                TextColumn::make('value_number')
                    ->label('Число')
                    ->formatStateUsing(
                        fn ($state) => $state === null
                            ? null
                            : ($fmt((float) $state).($unit ? " {$unit}" : ''))
                    )

                    ->sortable()
                    ->visible(fn () => $type === 'number')
                    ->toggleable(),
                IconColumn::make('value_boolean')
                    ->label('Нет / Да')->visible(fn () => $type === 'boolean')
                    ->boolean()
                    ->visible(fn () => $type === 'boolean')
                    ->toggleable(),
                TextColumn::make('pao_values')
                    ->label('Используемые опции')
                    ->wrap()
                    ->visible(fn () => $type === 'options')
                    ->toggleable(),
                TextColumn::make('value_min')
                    ->label('Min')
                    ->formatStateUsing(
                        fn ($state) => $state === null
                            ? null
                            : ($fmt((float) $state).($unit ? " {$unit}" : ''))
                    )
                    ->wrap()
                    ->visible(fn () => $type === 'range')
                    ->toggleable(),
                TextColumn::make('value_max')
                    ->label('Max')
                    ->formatStateUsing(
                        fn ($state) => $state === null
                            ? null
                            : ($fmt((float) $state).($unit ? " {$unit}" : ''))
                    )
                    ->wrap()
                    ->visible(fn () => $type === 'range')
                    ->toggleable(),

                // (по желанию) индикатор источника:
                // TextColumn::make('source')->label('Источник'), // 'pav' | 'pao'
            ])
            ->headerActions([
                Action::make('addProductUsingThisAttribute')
                    ->label('Добавить товар')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Привязать атрибут к товару')
                    ->modalSubmitActionLabel('Сохранить')
                    ->schema(function () {
                        $attr = $this->getOwnerRecord();
                        $type = $attr->usesOptions() ? 'options' : $attr->data_type;

                        $fields = [
                            // 1) Выбор товара (поиск по названию)
                            Select::make('product_id')
                                ->label('Товар')
                                ->searchable()
                                ->preload() // включаем предзагрузку
                                // Что показать сразу при открытии модалки:
                                ->options(function () {
                                    /** @var \App\Models\Attribute $attribute */
                                    $attribute = $this->getOwnerRecord();
                                    $attributeId = $attribute->getKey();
                                    $categoryIds = $attribute->categories()->pluck('id')->all();

                                    if (empty($categoryIds)) {
                                        return [];
                                    }

                                    $query = \App\Models\Product::query()
                                        // если нужно — раскомментируй:
                                        // ->where('is_active', true)
                                        ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));

                                    // --- ВАЖНО: исключаем товары, где атрибут уже заполнен ---

                                    if ($attribute->usesOptions()) {
                                        // select / multiselect → по PAO
                                        $query->whereDoesntHave('attributeOptions', function ($q) use ($attributeId) {
                                            $q->where('attribute_options.attribute_id', $attributeId);
                                        });
                                    } else {
                                        // PAV → в зависимости от data_type
                                        $type = $attribute->data_type;

                                        $query->whereDoesntHave('attributeValues', function ($q) use ($attributeId, $type) {
                                            $q->where('attribute_id', $attributeId)
                                                ->where(function ($q) use ($type) {
                                                    if ($type === 'boolean') {
                                                        // boolean: любое NOT NULL
                                                        $q->whereNotNull('value_boolean');
                                                    } elseif ($type === 'number') {
                                                        // number: value_si или value_number
                                                        $q->whereNotNull('value_si')
                                                            ->orWhereNotNull('value_number');
                                                    } elseif ($type === 'text') {
                                                        // text: непустой TRIM(value_text)
                                                        $q->whereNotNull('value_text')
                                                            ->whereRaw('TRIM(value_text) <> \'\'');
                                                    } elseif ($type === 'range') {
                                                        // range: любая из границ
                                                        $q->whereNotNull('value_min_si')
                                                            ->orWhereNotNull('value_max_si')
                                                            ->orWhereNotNull('value_min')
                                                            ->orWhereNotNull('value_max');
                                                    } else {
                                                        // fallback, если вдруг какой-то нестандартный тип
                                                        $q->whereNotNull('value_text')
                                                            ->orWhereNotNull('value_number')
                                                            ->orWhereNotNull('value_boolean')
                                                            ->orWhereNotNull('value_min')
                                                            ->orWhereNotNull('value_max');
                                                    }
                                                });
                                        });
                                    }

                                    return $query
                                        ->orderBy('name')
                                        ->limit(50)
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })

                                // Поиск по тем же допустимым товарам:
                                ->getSearchResultsUsing(function (string $queryString) {
                                    /** @var \App\Models\Attribute $attribute */
                                    $attribute = $this->getOwnerRecord();
                                    $attributeId = $attribute->getKey();
                                    $categoryIds = $attribute->categories()->pluck('id')->all();

                                    if (empty($categoryIds)) {
                                        return [];
                                    }

                                    $query = \App\Models\Product::query()
                                        // ->where('is_active', true)
                                        ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
                                        ->where('name', 'like', "%{$queryString}%");

                                    if ($attribute->usesOptions()) {
                                        $query->whereDoesntHave('attributeOptions', function ($q) use ($attributeId) {
                                            $q->where('attribute_options.attribute_id', $attributeId);
                                        });
                                    } else {
                                        $type = $attribute->data_type;

                                        $query->whereDoesntHave('attributeValues', function ($q) use ($attributeId, $type) {
                                            $q->where('attribute_id', $attributeId)
                                                ->where(function ($q) use ($type) {
                                                    if ($type === 'boolean') {
                                                        $q->whereNotNull('value_boolean');
                                                    } elseif ($type === 'number') {
                                                        $q->whereNotNull('value_si')
                                                            ->orWhereNotNull('value_number');
                                                    } elseif ($type === 'text') {
                                                        $q->whereNotNull('value_text')
                                                            ->whereRaw('TRIM(value_text) <> \'\'');
                                                    } elseif ($type === 'range') {
                                                        $q->whereNotNull('value_min_si')
                                                            ->orWhereNotNull('value_max_si')
                                                            ->orWhereNotNull('value_min')
                                                            ->orWhereNotNull('value_max');
                                                    } else {
                                                        $q->whereNotNull('value_text')
                                                            ->orWhereNotNull('value_number')
                                                            ->orWhereNotNull('value_boolean')
                                                            ->orWhereNotNull('value_min')
                                                            ->orWhereNotNull('value_max');
                                                    }
                                                });
                                        });
                                    }

                                    return $query
                                        ->orderBy('name')
                                        ->limit(50)
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })

                                ->noSearchResultsMessage('Ничего не найдено в привязанных категориях')
                                ->getOptionLabelUsing(fn ($value) => \App\Models\Product::find($value)?->name)
                                ->required(),

                        ];

                        // 2) Поля значения в зависимости от типа
                        if ($type === 'options') {
                            $fields[] = Select::make('option_ids')
                                ->label('Опции')
                                ->searchable()
                                ->preload()
                                ->multiple()
                                ->options(
                                    AttributeOption::query()
                                        ->where('attribute_id', $attr->getKey())
                                        ->orderBy('sort_order')
                                        ->orderBy('id')
                                        ->pluck('value', 'id')
                                )
                                ->required();
                        }

                        if ($type === 'text') {
                            $fields[] = TextInput::make('value_text')
                                ->label('Значение (текст)')
                                ->required();
                        }

                        if ($type === 'number') {
                            $fields[] = TextInput::make('value_number')
                                ->label('Значение (число'.($attr->unit?->symbol ? ', '.$attr->unit->symbol : '').')')
                                ->numeric()
                                ->required();
                        }

                        if ($type === 'boolean') {
                            $fields[] = Toggle::make('value_boolean')
                                ->label('Нет / Да')
                                ->inline(false)
                                ->required();
                        }

                        if ($type === 'range') {
                            $fields[] = TextInput::make('value_min')
                                ->label('Мин.')
                                ->numeric()
                                ->required();

                            $fields[] = TextInput::make('value_max')
                                ->label('Макс.')
                                ->numeric()
                                ->required();
                        }

                        return $fields;
                    })
                    ->action(function (array $data) {
                        $attr = $this->getOwnerRecord();
                        $type = $attr->usesOptions() ? 'options' : $attr->data_type;
                        $attributeId = $attr->getKey();
                        $productId = (int) $data['product_id'];
                        $allowedCategoryIds = $attr->categories()->pluck('id')->all();
                        $belongs = \App\Models\Product::query()
                            ->whereKey($productId)
                            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedCategoryIds))
                            ->exists();

                        if (! $belongs) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Товар не относится к категориям этого фильтра')
                                ->body('Выберите товар из привязанных к фильтру категорий.')
                                ->send();

                            return;
                        }

                        DB::transaction(function () use ($type, $attributeId, $productId, $data) {
                            // Всегда очищаем старые значения этого атрибута у товара
                            ProductAttributeValue::query()
                                ->where('attribute_id', $attributeId)
                                ->where('product_id', $productId)
                                ->delete();

                            ProductAttributeOption::query()
                                ->where('attribute_id', $attributeId)
                                ->where('product_id', $productId)
                                ->delete();

                            // Пишем новое значение в нужную таблицу
                            switch ($type) {
                                case 'options':
                                    $ids = (array) ($data['option_ids'] ?? []);
                                    if (! empty($ids)) {
                                        $rows = array_map(fn ($oid) => [
                                            'product_id' => $productId,
                                            'attribute_id' => $attributeId,
                                            'attribute_option_id' => (int) $oid,
                                        ], $ids);

                                        // быстрая вставка
                                        ProductAttributeOption::insert($rows);
                                    }
                                    break;

                                case 'text':
                                    ProductAttributeValue::create([
                                        'product_id' => $productId,
                                        'attribute_id' => $attributeId,
                                        'value_text' => (string) $data['value_text'],
                                    ]);
                                    break;

                                case 'number':
                                    ProductAttributeValue::create([
                                        'product_id' => $productId,
                                        'attribute_id' => $attributeId,
                                        'value_number' => $data['value_number'] !== '' ? (float) $data['value_number'] : null,
                                    ]);
                                    break;

                                case 'boolean':
                                    ProductAttributeValue::create([
                                        'product_id' => $productId,
                                        'attribute_id' => $attributeId,
                                        'value_boolean' => (bool) ($data['value_boolean'] ?? false),
                                    ]);
                                    break;

                                case 'range':
                                    $min = $data['value_min'] !== '' ? (float) $data['value_min'] : null;
                                    $max = $data['value_max'] !== '' ? (float) $data['value_max'] : null;
                                    ProductAttributeValue::create([
                                        'product_id' => $productId,
                                        'attribute_id' => $attributeId,
                                        'value_min' => $min,
                                        'value_max' => $max,
                                    ]);
                                    break;
                            }
                        });

                        // Обновим вью-таблицу
                        $this->getOwnerRecord()->refresh();
                        $this->dispatch('$refresh');

                        Notification::make()
                            ->success()
                            ->title('Значение сохранено')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('deleteLink')
                    ->label('Удалить связь')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Удалить связь атрибут ↔ товар?')
                    ->action(function ($record) {
                        $attributeId = (int) $record->attribute_id;
                        $productId = (int) $record->product_id;

                        DB::transaction(function () use ($attributeId, $productId) {
                            ProductAttributeValue::where('attribute_id', $attributeId)->where('product_id', $productId)->delete();
                            ProductAttributeOption::where('attribute_id', $attributeId)->where('product_id', $productId)->delete();
                        });

                        $this->getOwnerRecord()->refresh();
                        $this->dispatch('$refresh');

                        Notification::make()->success()->title('Связь удалена')->send();
                    }),
            ])
            ->filters([
                //
            ]);
    }
}
