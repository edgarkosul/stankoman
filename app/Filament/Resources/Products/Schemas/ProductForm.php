<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductWarranty;
use App\Enums\ProductWholesaleCurrency;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\PdfLinkBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\SellerRequisitesBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock;
use App\Models\Product;
use App\Support\Products\ProductCurrencyRateSyncService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 5, 'lg' => 5])
            ->components([
                Hidden::make('slug_manually_changed')
                    ->default(false)
                    ->dehydrated(false),

                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->unique()
                    ->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (
                        ?Product $record,
                        Get $get,
                        Set $set,
                        ?string $old,
                        ?string $state
                    ) {
                        if (! $record && ! $get('slug_manually_changed') && filled($state)) {
                            $set('slug', Str::slug($state));
                        }

                        if (filled($get('meta_title')) || blank($state)) {
                            return;
                        }
                        $price = (int) ($get('price_amount') ?? 0);
                        $discountRaw = $get('discount_price') ?? null;
                        $discount = $discountRaw === null ? null : (int) $discountRaw;

                        $hasDiscount = $price > 0 && $discount !== null && $discount > 0 && $discount < $price;
                        $finalPrice = $hasDiscount ? $discount : $price;
                        if ($finalPrice > 0) {
                            $priceFormatted = number_format($finalPrice, 0, ' ', ' ');
                            $title = "Купить {$state} по цене {$priceFormatted} ₽";
                        } else {
                            $title = "Купить {$state}";
                        }
                        $set('meta_title', $title);
                    }),
                TextInput::make('meta_title')
                    ->label('META Title')
                    ->maxLength(255)
                    ->helperText('Используется в SEO title страницы товара. Если оставить пустым, будет сгенерирован автоматически.')
                    ->columnSpanFull(),
                // TextInput::make('title')
                //     ->label('Legacy title')
                //     ->disabled()
                //     ->dehydrated(false)
                //     ->helperText('Устаревшее поле. Больше не используется в витрине и новых импортных данных.')
                //     ->visible(fn (?Product $record): bool => filled($record?->title))
                //     ->columnSpanFull(),
                Textarea::make('meta_description')
                    ->label('META Description')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('slug')
                    ->label('ЧПУ')
                    ->required()
                    ->unique()
                    ->columnSpanFull()
                    ->afterStateUpdated(function (Set $set) {
                        $set('slug_manually_changed', true);
                    }),
                Toggle::make('with_dns')
                    ->label('С НДС')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->required(),
                TextInput::make('sku')
                    ->label('Артикул')
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->default(null),
                TextInput::make('brand')
                    ->label('Бренд')
                    ->columnSpan(['default' => 2, 'lg' => 1])
                    ->default(null),
                TextInput::make('country')
                    ->label('Производитель')
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->default(null),
                Select::make('warranty')
                    ->label('Гарантия производителя')
                    ->options(ProductWarranty::options())
                    ->placeholder('Без гарантии')
                    ->columnSpan(['default' => 2, 'lg' => 1])
                    ->default(null),
                Toggle::make('in_stock')
                    ->label('В наличии')
                    ->columnSpan(['default' => 2, 'lg' => 1])
                    ->required(),
                Toggle::make('is_active')
                    ->label('Показывать на сайте')
                    ->columnSpan(['default' => 2, 'lg' => 1])
                    ->required(),
                Toggle::make('is_in_yml_feed')
                    ->label('Выгружать в Фид Яндекс.Маркет')
                    ->columnSpan(['default' => 2, 'lg' => 1])
                    ->required(),
                TextInput::make('promo_info')
                    ->label('Промо информация, акция, распродажи и пр.')
                    ->columnSpanFull()
                    ->default(null),

                TextInput::make('popularity')
                    ->label('Индекс популярности')
                    ->required()
                    ->default(0)
                    ->belowContent('Используется для фильтра по популярности. Товар с меньшим индексом будет расположен выше')
                    ->columnSpanFull()
                    ->numeric(),

                Section::make('Ценообразование')
                    ->columns(['default' => 2, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('wholesale_price')
                            ->label('Цена опт')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.0001')
                            ->minValue(0)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculatePricingFromSource($get, $set);
                            }),
                        Select::make('wholesale_currency')
                            ->label('Валюта')
                            ->options(ProductWholesaleCurrency::options())
                            ->default(ProductWholesaleCurrency::Rur->value)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $set('wholesale_currency', Product::normalizeWholesaleCurrency($state));

                                if ((bool) $get('auto_update_exchange_rate')) {
                                    self::syncAutomaticExchangeRate($get, $set);
                                }
                            }),
                        Toggle::make('auto_update_exchange_rate')
                            ->label('Обновлять по курсу ЦБ')
                            ->default(false)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                if ((bool) $state) {
                                    self::syncAutomaticExchangeRate($get, $set);
                                }
                            }),
                        TextInput::make('exchange_rate')
                            ->label('Курс валюты')
                            ->formatStateUsing(function (mixed $state): ?string {
                                $normalizedRate = Product::normalizeExchangeRate($state);

                                if ($normalizedRate === null) {
                                    return null;
                                }

                                return number_format($normalizedRate, 2, '.', '');
                            })
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
                            ->minValue(0)
                            ->disabled(fn (Get $get): bool => (bool) $get('auto_update_exchange_rate'))
                            ->dehydrated()
                            ->helperText(fn (Get $get): ?string => (bool) $get('auto_update_exchange_rate')
                                ? 'Курс обновляется автоматически по данным ЦБ РФ.'
                                : null)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculatePricingFromSource($get, $set);
                            }),
                        TextInput::make('wholesale_price_rub')
                            ->label('Опт, руб')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('numeric')
                            ->step(1)
                            ->minValue(0)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculateSitePriceAndMargin($get, $set);
                            }),
                        TextInput::make('markup_multiplier')
                            ->label('Наценка')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
                            ->minValue(0)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculatePricingFromSource($get, $set);
                            }),
                        TextInput::make('price_amount')
                            ->label('Цена на сайт, руб')
                            ->suffix('₽')
                            ->required()
                            ->default(0)
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(1)
                            ->minValue(0)
                            ->maxValue(4_294_967_295)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculatePricingMargin($get, $set);
                                self::recalculateDiscountPriceFromPercent($get, $set);
                            }),
                        TextInput::make('margin_amount_rub')
                            ->label('Маржа, руб')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
                            ->readOnly()
                            ->columnSpan(['default' => 1, 'lg' => 1]),
                        TextInput::make('discount_margin_amount_rub')
                            ->label('Маржа со скидкой, руб')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
                            ->readOnly()
                            ->dehydrated(false)
                            ->validatedWhenNotDehydrated(false)
                            ->formatStateUsing(function (Get $get): ?float {
                                return Product::calculateDiscountMarginAmountRub(
                                    $get('discount_price'),
                                    $get('wholesale_price_rub'),
                                );
                            })
                            ->columnSpan(['default' => 1, 'lg' => 1]),
                        TextInput::make('discount_percent')
                            ->label('Скидка в %')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
                            ->minValue(0)
                            ->maxValue(100)
                            ->dehydrated(false)
                            ->formatStateUsing(function (Get $get): ?float {
                                return Product::calculateDiscountPercent(
                                    $get('price_amount'),
                                    $get('discount_price'),
                                );
                            })
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculateDiscountPriceFromPercent($get, $set);
                            }),
                        TextInput::make('discount_price')
                            ->label('Цена со скидкой')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(1)
                            ->minValue(0)
                            ->columnSpan(['default' => 2, 'lg' => 1])
                            ->maxValue(4_294_967_295)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculateDiscountPercentFromPrice($get, $set);
                            })
                            ->helperText('Показывается только авторизованным. Должна быть меньше обычной цены.'),
                    ]),

                Tabs::make('description_tabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tabs\Tab::make('Описание')->schema([
                            self::makeProductContentEditor('description'),
                        ]),
                        Tabs\Tab::make('Инструкции')->schema([
                            self::makeProductContentEditor('instructions'),
                        ]),
                        Tabs\Tab::make('Видео')->schema([
                            self::makeProductContentEditor('video'),
                        ]),
                    ]),
                Repeater::make('specs')
                    ->label('Характеристики')
                    ->helperText('Редактирование в табличном виде. Пустые и дублирующиеся строки автоматически удаляются при сохранении.')
                    ->default([])
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('Параметр')
                            ->markAsRequired()
                            ->width('35%'),
                        TableColumn::make('Значение')
                            ->markAsRequired()
                            ->width('45%'),
                        TableColumn::make('Источник')
                            ->width('20%'),
                    ])
                    ->compact()
                    ->addActionLabel('Добавить характеристику')
                    ->cloneable()
                    ->reorderable()
                    ->schema([
                        TextInput::make('name')
                            ->label('Параметр')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('value')
                            ->label('Значение')
                            ->required()
                            ->maxLength(1000),
                        Select::make('source')
                            ->label('Источник')
                            ->options([
                                'manual' => 'manual',
                                'jsonld' => 'jsonld',
                                'inertia' => 'inertia',
                                'dom' => 'dom',
                                'import' => 'import',
                                'legacy' => 'legacy',
                                'yml' => 'yml',
                            ])
                            ->default('manual')
                            ->native(false),
                    ])
                    ->mutateDehydratedStateUsing(static fn (mixed $state): ?array => self::normalizeSpecsState($state)),

                FileUpload::make('image')
                    ->disk('public')
                    ->directory('pics')
                    ->image()
                    ->columnSpan(['default' => 2, 'lg' => 2])
                    ->imageEditor(),
                FileUpload::make('gallery')
                    ->disk('public')
                    ->directory('pics')
                    ->image()
                    ->multiple()
                    ->imagePreviewHeight('100')
                    ->reorderable()
                    ->appendFiles()
                    ->columnSpan(['default' => 2, 'lg' => 2])
                    ->default(null),

                // Textarea::make('short')
                //     ->label('Доп. описание')
                //     ->columnSpanFull()
                //     ->rows(6)
                //     ->default(null),
                Select::make('categories')
                    ->label('Категория товара')
                    ->hiddenOn('edit')
                    ->relationship(
                        name: 'categories',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->leaf(),
                    )
                    ->multiple()
                    ->preload()
                    ->columnSpan(['default' => 2, 'lg' => 2]),
            ]);
    }

    private static function makeProductContentEditor(string $field): RichEditor
    {
        return RichEditor::make($field)
            ->hiddenLabel()
            ->tools([
                RichEditorTool::make('clearContent')
                    ->label('Очистить')
                    ->icon(Heroicon::Trash)
                    ->activeStyling(false)
                    ->jsHandler("confirm('Очистить описание?') && ".'$getEditor'.'()?.chain().focus().clearContent().run()'),
            ])
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'textColor', 'strike', 'subscript', 'superscript', 'link'],
                ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                ['table', 'attachFiles', 'customBlocks'],
                ['undo', 'redo'],
                ['horizontalRule', 'grid', 'gridDelete', 'clearContent'],
            ])
            ->fileAttachmentsDisk('public')
            ->fileAttachmentsDirectory('pics')
            ->fileAttachmentsVisibility('public')
            ->customBlocks([
                ImageBlock::class,
                ImageGalleryBlock::class,
                PdfLinkBlock::class,
                SellerRequisitesBlock::class,
                RutubeVideoBlock::class,
                YoutubeVideoBlock::class,
                RawHtmlBlock::class,
            ]);
    }

    /**
     * @return array<int, array{name: string, value: string, source: string}>|null
     */
    public static function normalizeSpecsState(mixed $state): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        $normalized = [];
        $keys = [];

        foreach ($state as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = self::sanitizeSpecsString($row['name'] ?? null);
            $value = self::sanitizeSpecsString($row['value'] ?? null);
            $source = self::sanitizeSpecsString($row['source'] ?? null) ?? 'manual';

            if ($name === null || $value === null) {
                continue;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (isset($keys[$key])) {
                continue;
            }

            $keys[$key] = true;
            $normalized[] = [
                'name' => $name,
                'value' => $value,
                'source' => $source,
            ];
        }

        return $normalized === [] ? null : $normalized;
    }

    private static function recalculatePricingFromSource(Get $get, Set $set): void
    {
        $wholesalePriceRub = Product::calculateWholesalePriceRub(
            $get('wholesale_price'),
            $get('exchange_rate'),
        );

        $set('wholesale_price_rub', $wholesalePriceRub);

        $sitePriceAmount = Product::calculateSitePriceAmount(
            $wholesalePriceRub,
            $get('markup_multiplier'),
        );

        if ($sitePriceAmount !== null) {
            $set('price_amount', $sitePriceAmount);
        }

        self::recalculatePricingMargin($get, $set, $sitePriceAmount ?? $get('price_amount'), $wholesalePriceRub);
        self::recalculateDiscountPriceFromPercent($get, $set, $sitePriceAmount ?? $get('price_amount'));
        self::recalculateDiscountMargin($get, $set);
    }

    private static function recalculateSitePriceAndMargin(Get $get, Set $set): void
    {
        $sitePriceAmount = Product::calculateSitePriceAmount(
            $get('wholesale_price_rub'),
            $get('markup_multiplier'),
        );

        if ($sitePriceAmount !== null) {
            $set('price_amount', $sitePriceAmount);
        }

        self::recalculatePricingMargin($get, $set, $sitePriceAmount ?? $get('price_amount'));
        self::recalculateDiscountPriceFromPercent($get, $set, $sitePriceAmount ?? $get('price_amount'));
        self::recalculateDiscountMargin($get, $set);
    }

    private static function recalculatePricingMargin(
        Get $get,
        Set $set,
        mixed $sitePriceAmount = null,
        mixed $wholesalePriceRub = null
    ): void {
        $set('margin_amount_rub', Product::calculateMarginAmountRub(
            $sitePriceAmount ?? $get('price_amount'),
            $wholesalePriceRub ?? $get('wholesale_price_rub'),
        ));
    }

    private static function syncAutomaticExchangeRate(Get $get, Set $set): void
    {
        $currency = Product::normalizeWholesaleCurrency($get('wholesale_currency'));

        if ($currency === null) {
            return;
        }

        try {
            $exchangeRate = app(ProductCurrencyRateSyncService::class)->resolveRateForCurrency($currency);
        } catch (\Throwable $exception) {
            report($exception);

            return;
        }

        if ($exchangeRate === null) {
            return;
        }

        $set('exchange_rate', $exchangeRate);

        $wholesalePriceRub = Product::calculateWholesalePriceRub(
            $get('wholesale_price'),
            $exchangeRate,
        );

        $set('wholesale_price_rub', $wholesalePriceRub);

        $sitePriceAmount = Product::calculateSitePriceAmount(
            $wholesalePriceRub,
            $get('markup_multiplier'),
        );

        if ($sitePriceAmount !== null) {
            $set('price_amount', $sitePriceAmount);
        }

        self::recalculatePricingMargin($get, $set, $sitePriceAmount ?? $get('price_amount'), $wholesalePriceRub);
        self::recalculateDiscountPriceFromPercent($get, $set, $sitePriceAmount ?? $get('price_amount'));
        self::recalculateDiscountMargin($get, $set);
    }

    private static function recalculateDiscountPriceFromPercent(Get $get, Set $set, mixed $sitePriceAmount = null): void
    {
        $discountPrice = Product::calculateDiscountPrice(
            $sitePriceAmount ?? $get('price_amount'),
            $get('discount_percent'),
        );

        $set('discount_price', $discountPrice);
        self::recalculateDiscountMargin($get, $set, $discountPrice);
    }

    private static function recalculateDiscountPercentFromPrice(Get $get, Set $set): void
    {
        $set('discount_percent', Product::calculateDiscountPercent(
            $get('price_amount'),
            $get('discount_price'),
        ));

        self::recalculateDiscountMargin($get, $set);
    }

    private static function recalculateDiscountMargin(Get $get, Set $set, mixed $discountPrice = null): void
    {
        $set('discount_margin_amount_rub', Product::calculateDiscountMarginAmountRub(
            $discountPrice ?? $get('discount_price'),
            $get('wholesale_price_rub'),
        ));
    }

    private static function sanitizeSpecsString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
