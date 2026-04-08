<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductWarranty;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\PdfLinkBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\SellerRequisitesBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock;
use App\Models\Product;
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
                        TextInput::make('wholesale_currency')
                            ->label('Валюта')
                            ->maxLength(3)
                            ->default('RUB')
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $set('wholesale_currency', filled($state) ? Str::upper($state) : null);
                            }),
                        TextInput::make('exchange_rate')
                            ->label('Курс валюты')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.000001')
                            ->minValue(0)
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculatePricingFromSource($get, $set);
                            }),
                        TextInput::make('wholesale_price_rub')
                            ->label('Опт, руб')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
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
                            ->step('0.0001')
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
                            }),
                        TextInput::make('margin_amount_rub')
                            ->label('Маржа, руб')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('0.01')
                            ->columnSpan(['default' => 1, 'lg' => 1]),
                        TextInput::make('discount_price')
                            ->label('Цена со скидкой')
                            ->suffix('₽')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(1)
                            ->minValue(0)
                            ->columnSpan(['default' => 2, 'lg' => 1])
                            ->maxValue(4_294_967_295)
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

        $set('margin_amount_rub', Product::calculateMarginAmountRub(
            $sitePriceAmount ?? $get('price_amount'),
            $wholesalePriceRub,
        ));
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

        self::recalculatePricingMargin($get, $set);
    }

    private static function recalculatePricingMargin(Get $get, Set $set): void
    {
        $set('margin_amount_rub', Product::calculateMarginAmountRub(
            $get('price_amount'),
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
