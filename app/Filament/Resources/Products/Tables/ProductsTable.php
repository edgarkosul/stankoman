<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Models\Attribute;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Filament\Tables\Filters\Filter;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use App\Models\ProductAttributeValue;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\Products\Pages\CreateProduct;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->reorderable('popularity')
            ->defaultSort('popularity')
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('ЧПУ')
                    ->copyable()
                    ->copyMessage('ЧПУ скопировано в буфер обмена')
                    ->copyMessageDuration(1500)
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable(),
                TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable(),
                TextColumn::make('country')
                    ->label('Страна')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('price_amount')
                    ->label('Цена')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('discount_price')
                    ->label('Цена со скидкой')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('in_stock')
                    ->label('В наличии')
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->disabled(function (Product $record): bool {
                        $missing = $record->missingRequiredAttributes($record->primaryCategory()?->id);
                        return !$record->is_active && $missing->isNotEmpty();
                    })
                    ->afterStateUpdated(function (bool $state, Product $record): void {
                        if (! $state) { // выключение — всегда ок
                            return;
                        }

                        $categoryId = $record->primaryCategory()?->id;
                        $missing = $record->missingRequiredAttributes($categoryId);

                        if ($missing->isNotEmpty()) {
                            // Откатываем запись в БД без повторного эмита событий
                            $record->updateQuietly(['is_active' => false]);

                            Notification::make()
                                ->danger()
                                ->title('Нельзя включить публикацию')
                                ->body('Заполните: ' . $missing->pluck('name')->implode(', '))
                                ->persistent()
                                ->send();
                        }
                    })
                    ->tooltip(
                        fn(Product $r) => (!$r->is_active && $r->missingRequiredAttributes($r->primaryCategory()?->id)->isNotEmpty())
                            ? 'Заполните обязательные значения фильтров'
                            : null
                    )
                    ->label('На сайте'),
                ImageColumn::make('image')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('categories')
                    ->relationship('categories', 'name', fn(Builder $query) => $query->leaf()->orderBy('order'))
                    ->searchable()
                    ->preload(),

                // Товары вообще без категорий
                Filter::make('without_categories')
                    ->label('Без категорий')
                    ->toggle()
                    ->query(
                        fn(Builder $query): Builder =>
                        $query->whereDoesntHave('categories')
                    ),

                // Товары с категорией slug = staging
                Filter::make('staging_category')
                    ->label('Импортированные товары')
                    ->toggle()
                    ->query(
                        fn(Builder $query): Builder =>
                        $query->whereHas('categories', function (Builder $q): Builder {
                            return $q->where('slug', 'staging');
                        })
                    ),

            ])
            ->recordActions([
                ViewAction::make()
                    ->label(''),
                EditAction::make()
                    ->label(''),
                Action::make('duplicate')
                    ->label('Copy')
                    ->icon('heroicon-o-document-duplicate')
                    ->tooltip('Создать новый товар на основе этого')
                    ->url(fn(Product $record): string => CreateProduct::getUrl(['from' => $record->getKey()]))
                    ->openUrlInNewTab(),
                Action::make('open_public')
                    ->label('')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn($record) => route('product.show', $record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                BulkAction::make('massEdit')
                    ->label('Массовое изменение…')
                    ->slideOver()
                    ->schema([
                        Text::make(function ($livewire): string {
                            return 'Выбрано: ' . $livewire
                                ->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)
                                ->count();
                        })
                            ->color('info')
                            ->badge()
                            ->icon(Heroicon::CheckCircle),

                        Select::make('mode')
                            ->label('Что меняем')
                            ->options([
                                'fields'     => 'Параметры товара',
                                'categories' => 'Категории товара',
                                'filters'    => 'Значения фильтров',
                            ])
                            ->required()
                            ->live(),

                        // --- ПОЛЯ ---
                        Select::make('field')
                            ->label('Поле')
                            ->options([
                                'brand'          => 'Бренд',
                                'country'        => 'Производитель',
                                'discount_price' => 'Цена со скидкой (процент от цены)',
                                'with_dns'       => 'С НДС',
                                'in_stock'       => 'В наличии',
                                'is_active'      => 'Показывать на сайте',
                                'is_in_yml_feed' => 'Выгружать в Фид Яндекс.Маркет',
                                'warranty'       => 'Гарантия производителя',
                                'promo_info'     => 'Промо информация, акция, распродажи и пр.',
                            ])
                            ->visible(fn($get) => $get('mode') === 'fields')
                            ->required(fn($get) => $get('mode') === 'fields')
                            ->live(),

                        TextInput::make('brand_value')
                            ->label('Новый бренд')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'brand')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'brand'),

                        TextInput::make('country_value')
                            ->label('Новый производитель')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'country')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'country'),

                        TextInput::make('discount_price_percent')
                            ->label('Скидка, %')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'discount_price')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'discount_price'),

                        Toggle::make('with_dns_value')
                            ->label('С НДС')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'with_dns')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'with_dns'),

                        Toggle::make('in_stock_value')
                            ->label('В наличии')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'in_stock')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'in_stock'),

                        Toggle::make('is_active_value')
                            ->label('Показывать на сайте')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'is_active')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'is_active'),

                        Toggle::make('is_in_yml_feed_value')
                            ->label('Выгружать в Фид Яндекс.Маркет')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'is_in_yml_feed')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'is_in_yml_feed'),

                        TextInput::make('warranty_value')
                            ->label('Новая гарантия')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'warranty')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'warranty'),

                        Textarea::make('promo_info_value')
                            ->label('Промо информация')
                            ->visible(fn($get) => $get('mode') === 'fields' && $get('field') === 'promo_info')
                            ->required(fn($get) => $get('mode') === 'fields' && $get('field') === 'promo_info'),

                        // --- КАТЕГОРИИ ---
                        Select::make('cat_op')
                            ->label('Что делаем с категориями')
                            ->options([
                                'set_primary'   => 'Изменить основную категорию',
                                'attach_extra'  => 'Добавить доп. категории',
                                'detach_extra'  => 'Отвязать категории',
                            ])
                            ->visible(fn($get) => $get('mode') === 'categories')
                            ->required(fn($get) => $get('mode') === 'categories')
                            ->live(),

                        Select::make('primary_category_id')
                            ->label('Основная категория')
                            ->searchable()
                            ->options(fn() => \App\Models\Category::query()
                                ->leaf()
                                ->whereHas('products')
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->visible(fn($get) => $get('mode') === 'categories' && $get('cat_op') === 'set_primary')
                            ->required(fn($get) => $get('mode') === 'categories' && $get('cat_op') === 'set_primary'),

                        Select::make('extra_category_ids')
                            ->label('Категории')
                            ->multiple()
                            ->searchable()
                            ->options(function ($get, $livewire) {
                                $baseQuery = \App\Models\Category::query()->orderBy('name');

                                if ($get('cat_op') === 'detach_extra') {
                                    $selectedIds = $livewire
                                        ->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)
                                        ->pluck('id');

                                    if ($selectedIds->isEmpty()) {
                                        return [];
                                    }

                                    return $baseQuery
                                        ->whereHas('products', fn($q) => $q->whereIn('products.id', $selectedIds))
                                        ->pluck('name', 'id');
                                }

                                return $baseQuery
                                    ->leaf()
                                    ->whereHas('products')
                                    ->pluck('name', 'id');
                            }) // лучше вынести в ->getSearchResultsUsing(...)
                            ->visible(fn($get) => $get('mode') === 'categories' && in_array($get('cat_op'), ['attach_extra', 'detach_extra']))
                            ->required(fn($get) => $get('mode') === 'categories' && in_array($get('cat_op'), ['attach_extra', 'detach_extra'])),

                        // --- ФИЛЬТРЫ / АТРИБУТЫ ---
                        Select::make('attribute_id')
                            ->label('Атрибут')
                            ->searchable()
                            ->options(function ($get, $livewire) {
                                $productIds = $livewire
                                    ->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)
                                    ->pluck('id');

                                if ($productIds->isEmpty()) {
                                    return [];
                                }

                                // Сначала берём primary-категории выбранных товаров, фоллбек — любые категории товаров.
                                $categoryIds = DB::table('product_categories')
                                    ->whereIn('product_id', $productIds)
                                    ->where('is_primary', true)
                                    ->pluck('category_id');

                                if ($categoryIds->isEmpty()) {
                                    $categoryIds = DB::table('product_categories')
                                        ->whereIn('product_id', $productIds)
                                        ->pluck('category_id');
                                }

                                if ($categoryIds->isEmpty()) {
                                    return [];
                                }

                                return \App\Models\Attribute::query()
                                    ->whereHas('categories', fn($q) => $q->whereIn('categories.id', $categoryIds))
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->visible(fn($get) => $get('mode') === 'filters')
                            ->required(fn($get) => $get('mode') === 'filters')
                            ->live(),

                        TextInput::make('attr_number_value')
                            ->label('Значение')
                            ->numeric()
                            ->suffix(function ($get, $livewire) {
                                $attrId = (int) $get('attribute_id');

                                if (! $attrId) {
                                    return null;
                                }

                                $productIds = $livewire
                                    ->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)
                                    ->pluck('id');

                                if ($productIds->isEmpty()) {
                                    return null;
                                }

                                $categoryIds = DB::table('product_categories')
                                    ->whereIn('product_id', $productIds)
                                    ->where('is_primary', true)
                                    ->pluck('category_id');

                                if ($categoryIds->isEmpty()) {
                                    $categoryIds = DB::table('product_categories')
                                        ->whereIn('product_id', $productIds)
                                        ->pluck('category_id');
                                }

                                if ($categoryIds->isNotEmpty()) {
                                    $displayUnitId = DB::table('category_attribute')
                                        ->whereIn('category_id', $categoryIds)
                                        ->where('attribute_id', $attrId)
                                        ->value('display_unit_id');

                                    if ($displayUnitId) {
                                        return \App\Models\Unit::find($displayUnitId)?->symbol;
                                    }
                                }

                                return \App\Models\Attribute::with('unit')
                                    ->find($attrId)
                                    ?->unit
                                    ?->symbol;
                            })
                            ->visible(function ($get) {
                                $attrId = (int) $get('attribute_id');
                                $type = $attrId ? Attribute::find($attrId)?->data_type : null;

                                return $get('mode') === 'filters' && in_array($type, ['number', 'range'], true);
                            })
                            ->required(function ($get) {
                                $attrId = (int) $get('attribute_id');
                                $type = $attrId ? Attribute::find($attrId)?->data_type : null;

                                return $get('mode') === 'filters' && in_array($type, ['number', 'range'], true);
                            }),

                        Toggle::make('attr_bool_value')
                            ->label('Есть/нет')
                            ->visible(function ($get) {
                                $attrId = (int) $get('attribute_id');
                                $type = $attrId ? Attribute::find($attrId)?->data_type : null;

                                return $get('mode') === 'filters' && $type === 'boolean';
                            })
                            ->required(function ($get) {
                                $attrId = (int) $get('attribute_id');
                                $type = $attrId ? Attribute::find($attrId)?->data_type : null;

                                return $get('mode') === 'filters' && $type === 'boolean';
                            }),

                        TextInput::make('attr_text_value')
                            ->label('Значение')
                            ->visible(function ($get) {
                                $attrId = (int) $get('attribute_id');
                                $type = $attrId ? Attribute::find($attrId)?->data_type : null;

                                return $get('mode') === 'filters' && (! $type || $type === 'text');
                            })
                            ->required(function ($get) {
                                $attrId = (int) $get('attribute_id');
                                $type = $attrId ? Attribute::find($attrId)?->data_type : null;

                                return $get('mode') === 'filters' && (! $type || $type === 'text');
                            }),
                    ])
                    ->action(function (array $data, Collection $records) {

                        $ids = $records->modelKeys();

                        DB::transaction(function () use ($data, $ids) {

                            if ($data['mode'] === 'fields') {
                                $q = \App\Models\Product::query()->whereKey($ids);

                                switch ($data['field']) {
                                    case 'brand':
                                        $q->update(['brand' => $data['brand_value']]);
                                        break;
                                    case 'country':
                                        $q->update(['country' => $data['country_value']]);
                                        break;
                                    case 'discount_price':
                                        $percent = min(100, max(0, (float) $data['discount_price_percent']));

                                        $q->select(['id', 'price_amount'])
                                            ->chunkById(200, function ($chunk) use ($percent) {
                                                foreach ($chunk as $product) {
                                                    $basePrice = (int) $product->price_amount;
                                                    $discount  = (int) round($basePrice * (1 - ($percent / 100)));

                                                    $product->update(['discount_price' => max($discount, 0)]);
                                                }
                                            });
                                        break;
                                    case 'with_dns':
                                        $q->update(['with_dns' => (bool) $data['with_dns_value']]);
                                        break;
                                    case 'in_stock':
                                        $q->update(['in_stock' => (bool) $data['in_stock_value']]);
                                        break;
                                    case 'is_active':
                                        $q->update(['is_active' => (bool) $data['is_active_value']]);
                                        break;
                                    case 'is_in_yml_feed':
                                        $q->update(['is_in_yml_feed' => (bool) $data['is_in_yml_feed_value']]);
                                        break;
                                    case 'warranty':
                                        $q->update(['warranty' => $data['warranty_value']]);
                                        break;
                                    case 'promo_info':
                                        $q->update(['promo_info' => $data['promo_info_value']]);
                                        break;
                                }

                                return;
                            }

                            if ($data['mode'] === 'categories') {
                                \App\Models\Product::query()
                                    ->whereKey($ids)
                                    ->select('id')
                                    ->chunkById(200, function ($chunk) use ($data) {
                                        foreach ($chunk as $product) {
                                            $rel = $product->categories();

                                            switch ($data['cat_op']) {
                                                case 'set_primary':
                                                    $categoryId = (int) $data['primary_category_id'];

                                                    if (! $rel->where('category_id', $categoryId)->exists()) {
                                                        $rel->attach($categoryId, ['is_primary' => false]);
                                                    }

                                                    $product->setPrimaryCategory($categoryId);
                                                    break;

                                                case 'attach_extra':
                                                    $payload = collect($data['extra_category_ids'])
                                                        ->mapWithKeys(fn($id) => [$id => ['is_primary' => false]])
                                                        ->all();
                                                    $rel->syncWithoutDetaching($payload);
                                                    break;

                                                case 'detach_extra':
                                                    $rel->detach($data['extra_category_ids']);
                                                    break;
                                            }
                                        }
                                    });

                                return;
                            }

                            if ($data['mode'] === 'filters') {
                                $attribute = Attribute::findOrFail((int) $data['attribute_id']);

                                $rawValue = match ($attribute->data_type) {
                                    'boolean' => $data['attr_bool_value'] ?? null,
                                    'number', 'range' => $data['attr_number_value'] ?? null,
                                    default => $data['attr_text_value'] ?? null,
                                };

                                if ($attribute->isBoolean()) {
                                    $converted = $rawValue === '' ? null : filter_var($rawValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                                    $value = ($converted === null && $rawValue !== '') ? (bool) $rawValue : $converted;
                                } elseif ($attribute->isNumber()) {
                                    $value = $rawValue === '' ? null : (float) $rawValue;
                                } elseif ($attribute->data_type === 'range') {
                                    $num = $rawValue === '' ? null : (float) $rawValue;
                                    $value = ['min' => $num, 'max' => $num];
                                } else {
                                    $value = $rawValue === '' ? null : (string) $rawValue;
                                }

                                $allowedCategoryIds = DB::table('category_attribute')
                                    ->where('attribute_id', $attribute->id)
                                    ->pluck('category_id')
                                    ->all();

                                if (empty($allowedCategoryIds)) {
                                    Notification::make()
                                        ->warning()
                                        ->title('Атрибут не привязан к категориям')
                                        ->body('Значение не сохранено: атрибут не найден ни в одной основной категории выбранных товаров.')
                                        ->send();

                                    return;
                                }

                                $primaryByProduct = DB::table('product_categories')
                                    ->whereIn('product_id', $ids)
                                    ->where('is_primary', true)
                                    ->pluck('category_id', 'product_id');

                                $skipped = [];

                                foreach ($ids as $productId) {
                                    $primaryCategoryId = $primaryByProduct[$productId] ?? null;

                                    if (! $primaryCategoryId || ! in_array($primaryCategoryId, $allowedCategoryIds)) {
                                        $skipped[] = $productId;
                                        continue;
                                    }

                                    $pav = ProductAttributeValue::firstOrNew([
                                        'product_id'   => $productId,
                                        'attribute_id' => $attribute->id,
                                    ]);

                                    $pav->setTypedValue($attribute, $value);
                                    $pav->attribute()->associate($attribute);
                                    $pav->save();
                                }

                                if (! empty($skipped)) {
                                    $shown = array_slice($skipped, 0, 5);
                                    $more = count($skipped) - count($shown);

                                    Notification::make()
                                        ->warning()
                                        ->title('Часть товаров пропущена')
                                        ->body('Атрибут не привязан к основной категории товаров: ' . implode(', ', $shown) . ($more > 0 ? " и ещё {$more}" : ''))
                                        ->send();
                                }

                                return;
                            }
                        });
                    })
                    ->requiresConfirmation(),
            ]);
    }

}
