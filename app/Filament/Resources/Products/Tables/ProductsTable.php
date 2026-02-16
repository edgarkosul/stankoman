<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\RunSpecsMatchJob;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Support\Products\SpecsMatchService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
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

                        return ! $record->is_active && $missing->isNotEmpty();
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
                                ->body('Заполните: '.$missing->pluck('name')->implode(', '))
                                ->persistent()
                                ->send();
                        }
                    })
                    ->tooltip(
                        fn (Product $r) => (! $r->is_active && $r->missingRequiredAttributes($r->primaryCategory()?->id)->isNotEmpty())
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
                    ->relationship('categories', 'name', fn (Builder $query) => $query->leaf()->orderBy('order'))
                    ->searchable()
                    ->preload(),

                // Товары вообще без категорий
                Filter::make('without_categories')
                    ->label('Без категорий')
                    ->toggle()
                    ->query(
                        fn (Builder $query): Builder => $query->whereDoesntHave('categories')
                    ),

                // Товары с категорией slug = staging
                Filter::make('staging_category')
                    ->label('Импортированные товары')
                    ->toggle()
                    ->query(
                        fn (Builder $query): Builder => $query->whereHas('categories', function (Builder $q): Builder {
                            return $q->where('slug', 'staging');
                        })
                    ),

            ])
            ->recordActions([
                EditAction::make()
                    ->label(''),
                Action::make('duplicate')
                    ->label('Copy')
                    ->icon('heroicon-o-document-duplicate')
                    ->tooltip('Создать новый товар на основе этого')
                    ->url(fn (Product $record): string => CreateProduct::getUrl(['from' => $record->getKey()]))
                    ->openUrlInNewTab(),
                Action::make('open_public')
                    ->label('')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => route('product.show', $record))
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
                            return 'Выбрано: '.$livewire
                                ->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)
                                ->count();
                        })
                            ->color('info')
                            ->badge()
                            ->icon(Heroicon::CheckCircle),

                        Select::make('mode')
                            ->label('Что меняем')
                            ->options([
                                'fields' => 'Параметры товара',
                                'categories' => 'Категории товара',
                                'filters' => 'Значения фильтров',
                                'specs_match' => 'Specs JSON → атрибуты',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set, $livewire): void {
                                if ($state !== 'specs_match') {
                                    $set('attribute_proposals', []);

                                    return;
                                }

                                $targetCategoryId = (int) ($get('target_category_id') ?? 0);
                                $set('attribute_proposals', self::buildSpecsAttributeProposalsState($livewire, $targetCategoryId));
                            }),

                        // --- ПОЛЯ ---
                        Select::make('field')
                            ->label('Поле')
                            ->options([
                                'brand' => 'Бренд',
                                'country' => 'Производитель',
                                'discount_price' => 'Цена со скидкой (процент от цены)',
                                'with_dns' => 'С НДС',
                                'in_stock' => 'В наличии',
                                'is_active' => 'Показывать на сайте',
                                'is_in_yml_feed' => 'Выгружать в Фид Яндекс.Маркет',
                                'warranty' => 'Гарантия производителя',
                                'promo_info' => 'Промо информация, акция, распродажи и пр.',
                            ])
                            ->visible(fn ($get) => $get('mode') === 'fields')
                            ->required(fn ($get) => $get('mode') === 'fields')
                            ->live(),

                        TextInput::make('brand_value')
                            ->label('Новый бренд')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'brand')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'brand'),

                        TextInput::make('country_value')
                            ->label('Новый производитель')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'country')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'country'),

                        TextInput::make('discount_price_percent')
                            ->label('Скидка, %')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'discount_price')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'discount_price'),

                        Toggle::make('with_dns_value')
                            ->label('С НДС')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'with_dns')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'with_dns'),

                        Toggle::make('in_stock_value')
                            ->label('В наличии')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'in_stock')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'in_stock'),

                        Toggle::make('is_active_value')
                            ->label('Показывать на сайте')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'is_active')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'is_active'),

                        Toggle::make('is_in_yml_feed_value')
                            ->label('Выгружать в Фид Яндекс.Маркет')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'is_in_yml_feed')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'is_in_yml_feed'),

                        TextInput::make('warranty_value')
                            ->label('Новая гарантия')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'warranty')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'warranty'),

                        Textarea::make('promo_info_value')
                            ->label('Промо информация')
                            ->visible(fn ($get) => $get('mode') === 'fields' && $get('field') === 'promo_info')
                            ->required(fn ($get) => $get('mode') === 'fields' && $get('field') === 'promo_info'),

                        // --- КАТЕГОРИИ ---
                        Select::make('cat_op')
                            ->label('Что делаем с категориями')
                            ->options([
                                'set_primary' => 'Изменить основную категорию',
                                'attach_extra' => 'Добавить доп. категории',
                                'detach_extra' => 'Отвязать категории',
                            ])
                            ->visible(fn ($get) => $get('mode') === 'categories')
                            ->required(fn ($get) => $get('mode') === 'categories')
                            ->live(),

                        Select::make('primary_category_id')
                            ->label('Основная категория')
                            ->searchable()
                            ->options(fn () => \App\Models\Category::query()
                                ->leaf()
                                ->whereHas('products')
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->visible(fn ($get) => $get('mode') === 'categories' && $get('cat_op') === 'set_primary')
                            ->required(fn ($get) => $get('mode') === 'categories' && $get('cat_op') === 'set_primary'),

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
                                        ->whereHas('products', fn ($q) => $q->whereIn('products.id', $selectedIds))
                                        ->pluck('name', 'id');
                                }

                                return $baseQuery
                                    ->leaf()
                                    ->whereHas('products')
                                    ->pluck('name', 'id');
                            }) // лучше вынести в ->getSearchResultsUsing(...)
                            ->visible(fn ($get) => $get('mode') === 'categories' && in_array($get('cat_op'), ['attach_extra', 'detach_extra']))
                            ->required(fn ($get) => $get('mode') === 'categories' && in_array($get('cat_op'), ['attach_extra', 'detach_extra'])),

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
                                    ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->visible(fn ($get) => $get('mode') === 'filters')
                            ->required(fn ($get) => $get('mode') === 'filters')
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

                        // --- MATCHING SPECS JSON ---
                        Select::make('target_category_id')
                            ->label('Целевая категория')
                            ->searchable()
                            ->options(fn () => Category::query()
                                ->leaf()
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set, $livewire): void {
                                if ($get('mode') !== 'specs_match') {
                                    return;
                                }

                                $set('attribute_proposals', self::buildSpecsAttributeProposalsState($livewire, (int) $state));
                            })
                            ->visible(fn ($get) => $get('mode') === 'specs_match')
                            ->required(fn ($get) => $get('mode') === 'specs_match'),

                        Toggle::make('dry_run')
                            ->label('Только проверка (dry-run)')
                            ->default(true)
                            ->visible(fn ($get) => $get('mode') === 'specs_match')
                            ->required(fn ($get) => $get('mode') === 'specs_match'),

                        Toggle::make('only_empty_attributes')
                            ->label('Обрабатывать только пустые атрибуты')
                            ->default(true)
                            ->visible(fn ($get) => $get('mode') === 'specs_match')
                            ->required(fn ($get) => $get('mode') === 'specs_match'),

                        Toggle::make('overwrite_existing')
                            ->label('Перезаписывать существующие значения')
                            ->default(false)
                            ->visible(fn ($get) => $get('mode') === 'specs_match')
                            ->required(fn ($get) => $get('mode') === 'specs_match'),

                        Toggle::make('auto_create_options')
                            ->label('Автосоздавать отсутствующие option')
                            ->default(false)
                            ->visible(fn ($get) => $get('mode') === 'specs_match')
                            ->required(fn ($get) => $get('mode') === 'specs_match'),

                        Toggle::make('detach_staging_after_success')
                            ->label('После успешного apply убрать staging')
                            ->default(false)
                            ->visible(fn ($get) => $get('mode') === 'specs_match' && ! (bool) $get('dry_run')),

                        Repeater::make('attribute_proposals')
                            ->label('Мастер подтверждения новых атрибутов')
                            ->helperText('Строки собраны из unmatched spec.name. Для dry-run действия create/link будут зафиксированы только в отчете.')
                            ->default([])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->itemLabel(fn (array $state): string => (string) ($state['spec_name'] ?? 'Новый атрибут'))
                            ->collapsed()
                            ->columns(2)
                            ->schema([
                                TextInput::make('spec_name')
                                    ->label('spec.name')
                                    ->disabled()
                                    ->dehydrated(true),

                                TextInput::make('frequency')
                                    ->label('Частота')
                                    ->disabled()
                                    ->dehydrated(false),

                                Textarea::make('sample_values_text')
                                    ->label('Примеры значений')
                                    ->rows(2)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),

                                TextInput::make('suggested_data_type')
                                    ->label('Предложенный data_type')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('suggested_input_type')
                                    ->label('Предложенный input_type')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('confidence_label')
                                    ->label('Уверенность')
                                    ->disabled()
                                    ->dehydrated(false),

                                Hidden::make('suggested_unit_id')
                                    ->dehydrated(true),

                                Hidden::make('existing_attribute_id')
                                    ->dehydrated(true),

                                TextInput::make('existing_attribute_name')
                                    ->label('Точный глобальный атрибут')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => (int) ($get('existing_attribute_id') ?? 0) > 0),

                                TextInput::make('attribute_match_status')
                                    ->label('Статус совпадения атрибута')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),

                                TextInput::make('suggested_unit_label')
                                    ->label('Предложенная единица')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => in_array((string) ($get('create_data_type') ?? $get('suggested_data_type')), ['number', 'range'], true)),

                                TextInput::make('suggested_unit_confidence_label')
                                    ->label('Уверенность unit')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => in_array((string) ($get('create_data_type') ?? $get('suggested_data_type')), ['number', 'range'], true)),

                                Select::make('decision')
                                    ->label('Действие')
                                    ->options(fn (Get $get): array => self::decisionOptionsForProposal(
                                        hasExactMatch: (int) ($get('existing_attribute_id') ?? 0) > 0,
                                    ))
                                    ->default('ignore')
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateHydrated(function (mixed $state, Get $get, Set $set): void {
                                        $hasExactMatch = (int) ($get('existing_attribute_id') ?? 0) > 0;
                                        $options = self::decisionOptionsForProposal($hasExactMatch);

                                        if (! array_key_exists((string) $state, $options)) {
                                            $set('decision', self::defaultDecisionForProposal($hasExactMatch));
                                        }
                                    })
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                        if ($state !== 'link_existing') {
                                            return;
                                        }

                                        if ((int) ($get('link_attribute_id') ?? 0) > 0) {
                                            return;
                                        }

                                        $existingAttributeId = (int) ($get('existing_attribute_id') ?? 0);

                                        if ($existingAttributeId > 0) {
                                            $set('link_attribute_id', $existingAttributeId);
                                        }
                                    })
                                    ->columnSpanFull(),

                                Select::make('link_attribute_id')
                                    ->label('Существующий атрибут')
                                    ->searchable()
                                    ->options(fn (): array => self::attributeLinkOptions())
                                    ->visible(fn (Get $get): bool => $get('decision') === 'link_existing')
                                    ->required(fn (Get $get): bool => $get('decision') === 'link_existing' && (int) ($get('existing_attribute_id') ?? 0) <= 0),

                                Select::make('create_data_type')
                                    ->label('Новый data_type')
                                    ->options([
                                        'text' => 'text',
                                        'number' => 'number',
                                        'range' => 'range',
                                        'boolean' => 'boolean',
                                    ])
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                        $set('create_input_type', self::defaultInputTypeForDataType($state));

                                        if (! in_array((string) $state, ['number', 'range'], true)) {
                                            $set('create_unit_id', null);
                                            $set('create_additional_unit_ids', []);

                                            return;
                                        }

                                        if (! (int) ($get('create_unit_id') ?? 0)) {
                                            $set('create_unit_id', (int) ($get('suggested_unit_id') ?? 0) ?: null);
                                        }
                                    })
                                    ->visible(fn (Get $get): bool => $get('decision') === 'create_attribute')
                                    ->required(fn (Get $get): bool => $get('decision') === 'create_attribute'),

                                Select::make('create_input_type')
                                    ->label('Новый input_type')
                                    ->native(false)
                                    ->options(fn (Get $get): array => self::inputTypesForDataType((string) ($get('create_data_type') ?? 'text')))
                                    ->visible(fn (Get $get): bool => $get('decision') === 'create_attribute')
                                    ->required(fn (Get $get): bool => $get('decision') === 'create_attribute'),

                                Select::make('create_unit_id')
                                    ->label('Базовая единица')
                                    ->searchable()
                                    ->native(false)
                                    ->options(fn (Get $get): array => self::unitOptionsForProposal(
                                        dataType: (string) ($get('create_data_type') ?? 'text'),
                                        preferredUnitId: (int) ($get('suggested_unit_id') ?? 0),
                                        selectedUnitId: (int) ($get('create_unit_id') ?? 0),
                                    ))
                                    ->visible(fn (Get $get): bool => $get('decision') === 'create_attribute' && in_array((string) ($get('create_data_type') ?? 'text'), ['number', 'range'], true))
                                    ->required(fn (Get $get): bool => $get('decision') === 'create_attribute' && in_array((string) ($get('create_data_type') ?? 'text'), ['number', 'range'], true)),

                                Select::make('create_additional_unit_ids')
                                    ->label('Дополнительные единицы')
                                    ->multiple()
                                    ->searchable()
                                    ->native(false)
                                    ->options(fn (Get $get): array => self::additionalUnitOptionsForProposal((int) ($get('create_unit_id') ?? 0)))
                                    ->visible(fn (Get $get): bool => $get('decision') === 'create_attribute' && in_array((string) ($get('create_data_type') ?? 'text'), ['number', 'range'], true)),
                            ])
                            ->visible(fn (Get $get): bool => $get('mode') === 'specs_match' && (int) ($get('target_category_id') ?? 0) > 0),
                    ])
                    ->action(function (array $data, Collection $records) {

                        $ids = $records->modelKeys();

                        if (($data['mode'] ?? null) === 'specs_match') {
                            $targetCategoryId = (int) ($data['target_category_id'] ?? 0);
                            $isLeafCategory = Category::query()
                                ->leaf()
                                ->whereKey($targetCategoryId)
                                ->exists();

                            if (! $isLeafCategory) {
                                Notification::make()
                                    ->warning()
                                    ->title('Целевая категория не подходит')
                                    ->body('Выберите конечную (leaf) категорию для матчинга.')
                                    ->send();

                                return;
                            }

                            if ($ids === []) {
                                Notification::make()
                                    ->warning()
                                    ->title('Не выбраны товары')
                                    ->body('Выберите минимум один товар для запуска матчинга.')
                                    ->send();

                                return;
                            }

                            $dryRun = (bool) ($data['dry_run'] ?? true);
                            $decisionRows = self::normalizeAttributeDecisionRows($data['attribute_proposals'] ?? []);
                            $decisionResolution = app(SpecsMatchService::class)->resolveAttributeDecisions(
                                targetCategoryId: $targetCategoryId,
                                decisionRows: $decisionRows,
                                applyChanges: ! $dryRun,
                            );

                            $options = [
                                'target_category_id' => $targetCategoryId,
                                'dry_run' => $dryRun,
                                'only_empty_attributes' => (bool) ($data['only_empty_attributes'] ?? true),
                                'overwrite_existing' => (bool) ($data['overwrite_existing'] ?? false),
                                'auto_create_options' => (bool) ($data['auto_create_options'] ?? false),
                                'detach_staging_after_success' => (bool) ($data['detach_staging_after_success'] ?? false),
                                'attribute_name_map' => $decisionResolution['name_map'],
                                'ignored_spec_names' => $decisionResolution['ignored_spec_names'],
                                'preflight_issues' => $decisionResolution['issues'],
                            ];

                            $run = ImportRun::query()->create([
                                'type' => 'specs_match',
                                'status' => 'pending',
                                'columns' => [
                                    'product_ids' => $ids,
                                    'options' => $options,
                                ],
                                'totals' => [
                                    'create' => 0,
                                    'update' => 0,
                                    'same' => 0,
                                    'conflict' => 0,
                                    'error' => 0,
                                    'scanned' => 0,
                                    '_meta' => [
                                        'mode' => $dryRun ? 'dry-run' : 'write',
                                        'is_running' => true,
                                        'target_category_id' => $targetCategoryId,
                                        'selected_products' => count($ids),
                                        'only_empty_attributes' => $options['only_empty_attributes'],
                                        'overwrite_existing' => $options['overwrite_existing'],
                                        'auto_create_options' => $options['auto_create_options'],
                                        'detach_staging_after_success' => $options['detach_staging_after_success'],
                                        'attribute_decisions' => count($decisionRows),
                                        'attribute_links' => count($options['attribute_name_map']),
                                        'pav_matched' => 0,
                                        'pao_matched' => 0,
                                        'skipped' => 0,
                                    ],
                                ],
                                'source_filename' => null,
                                'stored_path' => null,
                                'user_id' => auth()->id(),
                                'started_at' => now(),
                            ]);

                            RunSpecsMatchJob::dispatch(
                                runId: $run->id,
                                productIds: array_map('intval', $ids),
                                options: $options,
                            );

                            Notification::make()
                                ->success()
                                ->title('Запуск поставлен в очередь')
                                ->body('Запуск #'.$run->id.' отправлен в очередь (режим: '.($dryRun ? 'dry-run' : 'apply').').')
                                ->send();

                            return;
                        }

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
                                                    $discount = (int) round($basePrice * (1 - ($percent / 100)));

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
                                                        ->mapWithKeys(fn ($id) => [$id => ['is_primary' => false]])
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
                                        'product_id' => $productId,
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
                                        ->body('Атрибут не привязан к основной категории товаров: '.implode(', ', $shown).($more > 0 ? " и ещё {$more}" : ''))
                                        ->send();
                                }

                                return;
                            }
                        });
                    })
                    ->requiresConfirmation(),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function buildSpecsAttributeProposalsState(mixed $livewire, int $targetCategoryId): array
    {
        if ($targetCategoryId <= 0 || ! is_object($livewire) || ! method_exists($livewire, 'getSelectedTableRecordsQuery')) {
            return [];
        }

        $productIds = $livewire
            ->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($productIds === []) {
            return [];
        }

        $suggestions = app(SpecsMatchService::class)->buildAttributeCreationSuggestions($productIds, $targetCategoryId);

        return array_map(function (array $suggestion): array {
            $createDataType = (string) $suggestion['suggested_data_type'];

            return [
                'spec_name' => (string) $suggestion['spec_name'],
                'frequency' => (int) $suggestion['frequency'],
                'sample_values_text' => implode('; ', (array) ($suggestion['sample_values'] ?? [])),
                'suggested_data_type' => (string) $suggestion['suggested_data_type'],
                'suggested_input_type' => (string) $suggestion['suggested_input_type'],
                'confidence_label' => (string) $suggestion['confidence_label'],
                'suggested_unit_id' => ($suggestion['suggested_unit_id'] ?? null) ? (int) $suggestion['suggested_unit_id'] : null,
                'suggested_unit_label' => (string) ($suggestion['suggested_unit_label'] ?? '—'),
                'suggested_unit_confidence_label' => (string) ($suggestion['suggested_unit_confidence_label'] ?? 'Низкая'),
                'existing_attribute_id' => ($suggestion['existing_attribute_id'] ?? null) ? (int) $suggestion['existing_attribute_id'] : null,
                'existing_attribute_name' => (string) ($suggestion['existing_attribute_name'] ?? ''),
                'attribute_match_status' => ($suggestion['existing_attribute_id'] ?? null)
                    ? 'Точный глобальный атрибут найден. Рекомендуем связать его с целевой категорией.'
                    : 'Точный глобальный атрибут не найден.',
                'decision' => self::defaultDecisionForProposal(
                    (int) ($suggestion['existing_attribute_id'] ?? 0) > 0,
                ),
                'link_attribute_id' => ($suggestion['existing_attribute_id'] ?? null) ? (int) $suggestion['existing_attribute_id'] : null,
                'create_data_type' => $createDataType,
                'create_input_type' => self::defaultInputTypeForDataType($createDataType),
                'create_unit_id' => ($suggestion['suggested_unit_id'] ?? null) ? (int) $suggestion['suggested_unit_id'] : null,
                'create_additional_unit_ids' => [],
            ];
        }, $suggestions);
    }

    /**
     * @return array<int, string>
     */
    private static function attributeLinkOptions(): array
    {
        return Attribute::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function inputTypesForDataType(string $dataType): array
    {
        return match ($dataType) {
            'number' => ['number' => 'number'],
            'range' => ['range' => 'range'],
            'boolean' => ['boolean' => 'boolean'],
            default => [
                'multiselect' => 'multiselect',
                'select' => 'select',
            ],
        };
    }

    private static function defaultInputTypeForDataType(?string $dataType): string
    {
        $options = self::inputTypesForDataType((string) $dataType);

        return array_key_first($options) ?? 'multiselect';
    }

    private static function normalizeInputTypeForDataType(mixed $inputType, string $dataType): string
    {
        $normalizedInputType = is_string($inputType) ? trim($inputType) : '';
        $options = self::inputTypesForDataType($dataType);

        if (! array_key_exists($normalizedInputType, $options)) {
            return self::defaultInputTypeForDataType($dataType);
        }

        return $normalizedInputType;
    }

    /**
     * @return array<string, string>
     */
    private static function decisionOptionsForProposal(bool $hasExactMatch): array
    {
        if ($hasExactMatch) {
            return [
                'link_existing' => 'Связать существующий атрибут с категорией',
                'ignore' => 'Игнорировать',
            ];
        }

        return [
            'create_attribute' => 'Создать новый глобальный атрибут и привязать',
            'ignore' => 'Игнорировать',
        ];
    }

    private static function defaultDecisionForProposal(bool $hasExactMatch): string
    {
        return $hasExactMatch ? 'link_existing' : 'ignore';
    }

    /**
     * @return array<int, string>
     */
    private static function unitOptionsForProposal(string $dataType, int $preferredUnitId = 0, int $selectedUnitId = 0): array
    {
        if (! in_array($dataType, ['number', 'range'], true)) {
            return [];
        }

        $unitContextId = $selectedUnitId > 0 ? $selectedUnitId : $preferredUnitId;
        $dimension = null;

        if ($unitContextId > 0) {
            $dimension = Unit::query()
                ->whereKey($unitContextId)
                ->value('dimension');
        }

        return self::unitOptionsByDimension(is_string($dimension) ? $dimension : null);
    }

    /**
     * @return array<int, string>
     */
    private static function additionalUnitOptionsForProposal(int $baseUnitId): array
    {
        if ($baseUnitId <= 0) {
            return [];
        }

        $dimension = Unit::query()
            ->whereKey($baseUnitId)
            ->value('dimension');

        return collect(self::unitOptionsByDimension(is_string($dimension) ? $dimension : null))
            ->except($baseUnitId)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function unitOptionsByDimension(?string $dimension): array
    {
        $query = Unit::query()
            ->select(['id', 'name', 'symbol', 'dimension'])
            ->orderBy('name');

        if ($dimension !== null) {
            $query->where('dimension', $dimension);
        }

        return $query
            ->get()
            ->mapWithKeys(function (Unit $unit): array {
                $label = $unit->name;

                if ($unit->symbol) {
                    $label .= ' ('.$unit->symbol.')';
                }

                if ($unit->dimension) {
                    $label .= ' — '.$unit->dimension;
                }

                return [(int) $unit->id => $label];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeAttributeDecisionRows(mixed $rawRows): array
    {
        if (! is_array($rawRows)) {
            return [];
        }

        return collect($rawRows)
            ->filter(fn ($row): bool => is_array($row) && ($row['spec_name'] ?? null) !== null)
            ->map(function (array $row): array {
                $linkAttributeId = $row['link_attribute_id'] ?? null;
                $createDataType = (string) ($row['create_data_type'] ?? 'text');

                if ((int) $linkAttributeId <= 0 && (int) ($row['existing_attribute_id'] ?? 0) > 0) {
                    $linkAttributeId = (int) $row['existing_attribute_id'];
                }

                return [
                    'spec_name' => (string) ($row['spec_name'] ?? ''),
                    'decision' => (string) ($row['decision'] ?? 'ignore'),
                    'link_attribute_id' => $linkAttributeId,
                    'create_data_type' => $createDataType,
                    'create_input_type' => self::normalizeInputTypeForDataType(
                        $row['create_input_type'] ?? null,
                        $createDataType,
                    ),
                    'create_unit_id' => $row['create_unit_id'] ?? null,
                    'create_additional_unit_ids' => is_array($row['create_additional_unit_ids'] ?? null)
                        ? $row['create_additional_unit_ids']
                        : [],
                ];
            })
            ->values()
            ->all();
    }
}
