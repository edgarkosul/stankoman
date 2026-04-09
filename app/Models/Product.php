<?php

namespace App\Models;

use App\Enums\ProductWarranty;
use App\Enums\ProductWholesaleCurrency;
use App\Models\Attribute as AttributeDef;
use App\Models\Pivots\ProductCategory;
use App\Support\ImageDerivativesResolver;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute as EloquentAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * Модель товара (ядро каталога).
 *
 * @property int $id
 * @property string $name
 * @property string|null $title
 * @property string|null $name_normalized
 * @property string $slug
 * @property string|null $sku
 * @property string|null $brand
 * @property string|null $country
 * @property int $price_amount
 * @property int|null $discount_price
 * @property string $currency
 * @property string|null $wholesale_price
 * @property string|null $wholesale_currency
 * @property string|null $exchange_rate
 * @property string|null $wholesale_price_rub
 * @property string|null $markup_multiplier
 * @property string|null $margin_amount_rub
 * @property bool $auto_update_exchange_rate
 * @property bool $in_stock
 * @property int|null $qty
 * @property int $popularity
 * @property bool $is_active
 * @property bool $is_in_yml_feed
 * @property ProductWarranty|null $warranty
 * @property bool $with_dns
 * @property string|null $short
 * @property string|null $description
 * @property string|null $extra_description
 * @property string|null $instructions
 * @property string|null $video
 * @property array<array-key, mixed>|null $specs
 * @property string|null $image
 * @property string|null $thumb
 * @property array<array-key, mixed>|null $gallery
 * @property-read string|null $image_url
 * @property-read string|null $image_webp_srcset
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $promo_info
 */
class Product extends Model
{
    use Searchable;
    /* ======================================================================
     |  Атрибуты/касты
     | ====================================================================== */

    /** Массовое заполнение полей. */
    protected $fillable = [
        'name',
        'title',
        'slug',
        'sku',
        'brand',
        'country',
        'price_amount',
        'currency',
        'discount_price',
        'wholesale_price',
        'wholesale_currency',
        'exchange_rate',
        'wholesale_price_rub',
        'markup_multiplier',
        'margin_amount_rub',
        'auto_update_exchange_rate',
        'in_stock',
        'qty',
        'popularity',
        'is_active',
        'is_in_yml_feed',
        'warranty',
        'with_dns',
        'short',
        'description',
        'extra_description',
        'instructions',
        'video',
        'specs',
        'image',
        'thumb',
        'gallery',
        'meta_title',
        'meta_description',
        'promo_info',
    ];

    /** Приведения типов. */
    protected $casts = [
        'price_amount' => 'int',
        'discount_price' => 'int',
        'wholesale_price' => 'decimal:4',
        'exchange_rate' => 'decimal:2',
        'wholesale_price_rub' => 'decimal:0',
        'markup_multiplier' => 'decimal:2',
        'margin_amount_rub' => 'decimal:2',
        'auto_update_exchange_rate' => 'bool',
        'qty' => 'int',
        'popularity' => 'int',
        'in_stock' => 'bool',
        'is_active' => 'bool',
        'with_dns' => 'bool',
        'gallery' => 'array',
        'specs' => 'json:unicode',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_in_yml_feed' => 'bool',
        'warranty' => ProductWarranty::class,
    ];

    public function searchableAs(): string
    {
        return 'stankoman_products';
    }

    public function toSearchableArray(): array
    {
        // Оригиналы
        $name = (string) $this->name;
        $sku = (string) ($this->sku ?? '');
        $brand = (string) ($this->brand ?? '');
        // Латиница (для кросс-скриптового поиска)
        $nameLatin = $this->toLatin($name);
        $brandLatin = $this->toLatin($brand);
        $searchTerms = $this->buildSearchTerms($name, $sku);

        // Можно добавить что-то ещё, что полезно для поиска
        return [
            'id' => (int) $this->id,
            'name' => $name,
            'name_latin' => $nameLatin,
            'brand' => $brand,
            'brand_latin' => $brandLatin,
            'sku' => $sku,
            'search_terms' => $searchTerms,
            'price' => (float) $this->price,
            'discount_price' => (float) ($this->discount_price ?? 0),
            // при желании: category_id, in_stock, и т. п.
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return (bool) ($this->is_active ?? true);
    }

    public static function calculateWholesalePriceRub(mixed $wholesalePrice, mixed $exchangeRate): ?float
    {
        $wholesalePrice = self::nullableFloat($wholesalePrice);
        $exchangeRate = self::normalizeExchangeRate($exchangeRate);

        if ($wholesalePrice === null || $exchangeRate === null) {
            return null;
        }

        return round($wholesalePrice * $exchangeRate, 0);
    }

    public static function calculateSitePriceAmount(mixed $wholesalePriceRub, mixed $markupMultiplier): ?int
    {
        $wholesalePriceRub = self::nullableFloat($wholesalePriceRub);
        $markupMultiplier = self::normalizeMarkupMultiplier($markupMultiplier);

        if ($wholesalePriceRub === null || $markupMultiplier === null) {
            return null;
        }

        return max(0, (int) round($wholesalePriceRub * $markupMultiplier));
    }

    public static function calculateMarginAmountRub(mixed $sitePriceAmount, mixed $wholesalePriceRub): ?float
    {
        $sitePriceAmount = self::nullableFloat($sitePriceAmount);
        $wholesalePriceRub = self::nullableFloat($wholesalePriceRub);

        if ($sitePriceAmount === null || $wholesalePriceRub === null) {
            return null;
        }

        return round($sitePriceAmount - $wholesalePriceRub, 2);
    }

    public static function calculateDiscountPercent(mixed $sitePriceAmount, mixed $discountPrice): ?float
    {
        $sitePriceAmount = self::nullableFloat($sitePriceAmount);
        $discountPrice = self::nullableFloat($discountPrice);

        if ($sitePriceAmount === null || $discountPrice === null || $sitePriceAmount <= 0) {
            return null;
        }

        $discountPrice = min(max($discountPrice, 0), $sitePriceAmount);

        return round((1 - ($discountPrice / $sitePriceAmount)) * 100, 2);
    }

    public static function calculateDiscountPrice(mixed $sitePriceAmount, mixed $discountPercent): ?int
    {
        $sitePriceAmount = self::nullableFloat($sitePriceAmount);
        $discountPercent = self::normalizeDiscountPercent($discountPercent);

        if ($sitePriceAmount === null || $discountPercent === null) {
            return null;
        }

        $discountPercent = min(max($discountPercent, 0), 100);

        return max(0, (int) round($sitePriceAmount * (1 - ($discountPercent / 100))));
    }

    public static function calculateDiscountMarginAmountRub(mixed $discountPrice, mixed $wholesalePriceRub): ?float
    {
        $discountPrice = self::nullableFloat($discountPrice);
        $wholesalePriceRub = self::nullableFloat($wholesalePriceRub);

        if ($discountPrice === null || $wholesalePriceRub === null) {
            return null;
        }

        return round($discountPrice - $wholesalePriceRub, 2);
    }

    public static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));

            if ($value === '') {
                return null;
            }
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public static function normalizeWholesaleCurrency(mixed $value): ?string
    {
        return ProductWholesaleCurrency::normalizeInput($value);
    }

    public static function normalizeExchangeRate(mixed $value): ?float
    {
        $value = self::nullableFloat($value);

        return $value === null ? null : round($value, 2);
    }

    public static function normalizeMarkupMultiplier(mixed $value): ?float
    {
        $value = self::nullableFloat($value);

        return $value === null ? null : round($value, 2);
    }

    public static function normalizeDiscountPercent(mixed $value): ?float
    {
        $value = self::nullableFloat($value);

        return $value === null ? null : round($value, 2);
    }

    /* ======================================================================
     |  Маршрутизация
     | ====================================================================== */

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function warrantyDisplay(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: fn ($value, array $attributes): ?string => ProductWarranty::tryFrom((string) ($attributes['warranty'] ?? ''))?->label(),
        );
    }

    /* ======================================================================
     |  Связи
     | ====================================================================== */

    /** Категории товара. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories')
            ->using(ProductCategory::class)
            ->withPivot('is_primary');
    }

    public function supplierReferences(): HasMany
    {
        return $this->hasMany(ProductSupplierReference::class);
    }

    public function importMedia(): HasMany
    {
        return $this->hasMany(ProductImportMedia::class);
    }

    /**
     * Значения PAV (text/number/boolean/range).
     * Сразу подгружаем `attribute` для предотвращения N+1 в valueFor()/attr().
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->with('attribute');
    }

    /**
     * Опции атрибутов (select/multiselect) через pivot.
     */
    public function attributeOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'product_attribute_option',
            'product_id',
            'attribute_option_id'
        )
            ->using(ProductAttributeOption::class)
            ->withPivot('attribute_id')
            ->withTimestamps();
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $attributes = $product->getAttributes();
            $hasLoadedName = array_key_exists('name', $attributes);

            if ($hasLoadedName && ($product->isDirty('name') || $product->name_normalized === null || ! $product->exists)) {
                $product->name_normalized = NameNormalizer::normalize($product->name);
            }

            // Аккуратная генерация slug, если он ещё не задан
            if (! $hasLoadedName || $product->slug || ! $product->name) {
                return;
            }

            // Базовый slug из имени
            $base = Str::slug($product->name) ?: 'product';
            $slug = $base;
            $i = 2;

            // Обеспечиваем уникальность slug
            while (
                static::query()
                    ->where('slug', $slug)
                    ->when(
                        $product->exists,
                        fn ($q) => $q->where('id', '!=', $product->id)
                    )
                    ->exists()
            ) {
                $slug = $base.'-'.$i;
                $i++;
            }

            $product->slug = $slug;
        });
    }

    /* ======================================================================
     |  Мутаторы/аксессоры контента
     | ====================================================================== */

    /**
     * Описание: всегда не пустой HTML-блок.
     */
    protected function description(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: fn ($value) => (is_string($value) && trim($value) !== '') ? $value : '<p></p>',
            set: fn ($value) => (is_string($value) && trim($value) !== '') ? trim($value) : '<p></p>',
        );
    }

    /**
     * Доп. описание: всегда не пустой HTML-блок.
     */
    protected function extraDescription(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: fn ($value) => (is_string($value) && trim($value) !== '') ? $value : '<p></p>',
            set: fn ($value) => (is_string($value) && trim($value) !== '') ? trim($value) : '<p></p>',
        );
    }

    /**
     * Вычисляемое поле `price`: выдаём float из `price_amount` (int).
     */
    public function getPriceAttribute(): float
    {
        return (float) $this->price_amount;
    }

    protected function imageUrl(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value, array $attributes) {
                $path = $attributes['image'] ?? null;
                if (! is_string($path) || trim($path) === '') {
                    return null;
                }

                $path = trim($path);

                if (Str::startsWith($path, ['http://', 'https://', '/'])) {
                    return $path;
                }

                if (Str::startsWith($path, 'storage/')) {
                    return '/'.$path;
                }

                return Storage::disk('public')->url($path);
            },
        );
    }

    protected function imageWebpSrcset(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value, array $attributes) {
                $path = $attributes['image'] ?? null;
                if (! is_string($path) || trim($path) === '') {
                    return null;
                }

                $path = trim($path);

                if (Str::startsWith($path, ['http://', 'https://', '//'])) {
                    return null;
                }

                if (Str::startsWith($path, '/storage/')) {
                    $path = Str::after($path, '/storage/');
                } elseif (Str::startsWith($path, 'storage/')) {
                    $path = Str::after($path, 'storage/');
                } elseif (Str::startsWith($path, '/')) {
                    return null;
                }

                if ($path === '') {
                    return null;
                }

                return app(ImageDerivativesResolver::class)->buildWebpSrcset($path);
            },
        );
    }

    // 2) Добавь аксессоры (рядом с твоим getPriceAttribute()):
    protected function priceInt(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: fn ($value, $attr) => (int) ($attr['price_amount'] ?? 0),
        );
    }

    /** Нормализованная скидочная цена: int|null (null, если 0 или не задана) */
    protected function discount(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value, $attr) {
                $raw = $attr['discount_price'] ?? null;
                if ($raw === null) {
                    return null;
                }
                $v = (int) $raw;

                return $v > 0 ? $v : null;
            },
        );
    }

    /** Есть ли валидная скидка */
    protected function hasDiscount(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value, $attr) {
                $price = (int) ($attr['price_amount'] ?? 0);
                $discount = $attr['discount_price'] ?? null;
                if ($discount === null) {
                    return false;
                }
                $d = (int) $discount;

                return $price > 0 && $d > 0 && $d < $price;
            },
        );
    }

    /** Процент скидки (целое число) */
    protected function discountPercent(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value, $attr) {
                $price = (int) ($attr['price_amount'] ?? 0);
                $discount = $attr['discount_price'] ?? null;
                if ($discount === null) {
                    return null;
                }
                $d = (int) $discount;
                if ($price <= 0 || $d <= 0 || $d >= $price) {
                    return null;
                }

                return (int) round(100 - ($d / $price) * 100);
            },
        );
    }

    /** Итоговая цена к показу: скидка (если валидна) иначе базовая */
    protected function priceFinal(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: function ($value, $attr) {
                $price = (int) ($attr['price_amount'] ?? 0);
                $discount = $attr['discount_price'] ?? null;
                $d = $discount === null ? null : (int) $discount;
                $has = $price > 0 && $d !== null && $d > 0 && $d < $price;

                return $has ? $d : $price;
            },
        );
    }

    /* ======================================================================
     |  Работа с категориями товара
     | ====================================================================== */

    /**
     * Основная категория (по pivot.is_primary).
     */
    public function primaryCategory(): ?Category
    {
        if ($this->relationLoaded('categories')) {
            return $this->categories->firstWhere('pivot.is_primary', true);
        }

        return $this->categories()
            ->wherePivot('is_primary', true)
            ->first();
    }

    /**
     * Назначить основную категорию.
     */
    public function setPrimaryCategory(int|Category $category): void
    {
        $categoryId = $category instanceof Category ? $category->getKey() : $category;

        DB::table('product_categories')
            ->where('product_id', $this->getKey())
            ->update(['is_primary' => false]);

        DB::table('product_categories')
            ->where('product_id', $this->getKey())
            ->where('category_id', $categoryId)
            ->update(['is_primary' => true]);

        $this->unsetRelation('categories');
    }

    /**
     * Полностью заменить основную категорию и удалить прежние primary-привязки.
     */
    public function replacePrimaryCategory(int|Category $category): void
    {
        $categoryId = $category instanceof Category ? $category->getKey() : $category;

        if ($categoryId <= 0) {
            return;
        }

        $currentPrimaryCategoryIds = $this->categories()
            ->wherePivot('is_primary', true)
            ->pluck('categories.id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $categoryId)
            ->values()
            ->all();

        if (! $this->categories()->whereKey($categoryId)->exists()) {
            $this->categories()->attach($categoryId, ['is_primary' => false]);
        }

        $this->setPrimaryCategory($categoryId);

        if ($currentPrimaryCategoryIds !== []) {
            $this->categories()->detach($currentPrimaryCategoryIds);
        }

        $this->unsetRelation('categories');
    }

    /**
     * Набор атрибутов основной категории (если задана).
     *
     * @return Collection<int,AttributeDef>
     */
    public function getPrimaryCategoryAttributes(): Collection
    {
        return $this->primaryCategory()?->attributeDefs ?? collect();
    }

    /* ======================================================================
     |  Доступ/форматирование атрибутов товара
     | ====================================================================== */

    /**
     * (Legacy) Вернуть первую запись PAV по слагу атрибута.
     *
     * @deprecated Используйте attr() / attrLabel() / setAttributeValue().
     */
    public function valueFor(string $attributeSlug): ?ProductAttributeValue
    {
        if (! $this->relationLoaded('attributeValues')) {
            $this->load('attributeValues.attribute');
        }

        return $this->attributeValues->first(
            fn ($v) => $v->attribute && $v->attribute->slug === $attributeSlug
        );
    }

    /**
     * Универсальный геттер значений атрибута по slug/id.
     *
     * Возвращает структуры:
     *  - select/multiselect: ['type' => 'options', 'labels' => string[], 'ids' => int[]]
     *  - text:               ['type' => 'text',    'values' => string[]]
     *  - number:             ['type' => 'number',  'values' => float[]]
     *  - boolean:            ['type' => 'boolean', 'values' => bool[]]
     *  - range:              ['type' => 'range',   'values' => array{min:?float,max:?float}[]]
     */
    public function attr(string|int $attr): ?array
    {
        $attribute = is_numeric($attr)
            ? AttributeDef::find((int) $attr)
            : AttributeDef::where('slug', $attr)->first();

        if (! $attribute) {
            return null;
        }

        // select / multiselect
        if ($attribute->usesOptions()) {
            $rows = $this->attributeOptions()
                ->where('attribute_options.attribute_id', $attribute->id)
                ->orderBy('attribute_options.sort_order')
                ->get(['attribute_options.id', 'attribute_options.value']);

            return [
                'type' => 'options',
                'labels' => $rows->pluck('value')->all(),
                'ids' => $rows->pluck('id')->all(),
            ];
        }

        // PAV-ветка
        $type = $attribute->data_type; // 'text' | 'number' | 'boolean' | 'range'

        switch ($type) {
            case 'text':
                $values = $this->attributeValues()
                    ->where('attribute_id', $attribute->id)
                    ->whereNotNull('value_text')
                    ->pluck('value_text')
                    ->map(fn ($v) => (string) $v)
                    ->all();

                return ['type' => 'text', 'values' => $values];

            case 'number':
                $values = $this->attributeValues()
                    ->where('attribute_id', $attribute->id)
                    ->whereNotNull('value_number')
                    ->pluck('value_number')
                    ->map(fn ($v) => is_null($v) ? null : (float) $v)
                    ->filter(fn ($v) => $v !== null)
                    ->values()
                    ->all();

                return ['type' => 'number', 'values' => $values];

            case 'boolean':
                $values = $this->attributeValues()
                    ->where('attribute_id', $attribute->id)
                    ->whereNotNull('value_boolean')
                    ->pluck('value_boolean')
                    ->map(fn ($v) => (bool) $v)
                    ->all();

                return ['type' => 'boolean', 'values' => $values];

            case 'range':
                $rows = $this->attributeValues()
                    ->where('attribute_id', $attribute->id)
                    ->get(['value_min', 'value_max']);

                $values = $rows->map(function ($r) {
                    $min = $r->value_min;
                    $max = $r->value_max;

                    if ($min === null && $max === null) {
                        return null;
                    }

                    return [
                        'min' => $min === null ? null : (float) $min,
                        'max' => $max === null ? null : (float) $max,
                    ];
                })
                    ->filter(fn ($v) => $v !== null)
                    ->values()
                    ->all();

                return ['type' => 'range', 'values' => $values];

            default:
                // Фоллбек на смешанный формат
                $rows = $this->attributeValues()
                    ->where('attribute_id', $attribute->id)
                    ->get(['value_text', 'value_number', 'value_min', 'value_max', 'value_boolean']);

                $values = $rows->map(function ($r) {
                    if ($r->value_min !== null || $r->value_max !== null) {
                        return ['min' => $r->value_min, 'max' => $r->value_max];
                    }
                    if ($r->value_number !== null) {
                        return (float) $r->value_number;
                    }
                    if ($r->value_text !== null) {
                        return (string) $r->value_text;
                    }
                    if ($r->value_boolean !== null) {
                        return (bool) $r->value_boolean;
                    }

                    return null;
                })
                    ->filter(fn ($v) => $v !== null)
                    ->values()
                    ->all();

                return ['type' => 'pav', 'values' => $values];
        }
    }

    /**
     * Установить/обновить значение атрибута по слагу.
     *
     * Для select/multiselect — пишется в pivot (таблица product_attribute_option).
     * Для PAV — пишется/обновляется запись в product_attribute_values.
     *
     * @param  mixed  $value  select: int|null; multiselect: int[]; PAV: см. ProductAttributeValue::setTypedValue()
     * @return bool|ProductAttributeValue
     */
    public function setAttributeValue(string $attributeSlug, $value)
    {
        $attribute = AttributeDef::where('slug', $attributeSlug)->firstOrFail();

        if ($attribute->usesOptions()) {
            // pivot (options)
            if ($attribute->input_type === 'select') {
                ProductAttributeOption::setSingle(
                    $this->getKey(),
                    $attribute->id,
                    $value ? (int) $value : null
                );
            } else {
                $ids = is_array($value) ? $value : (array) $value;
                ProductAttributeOption::setForProductAttribute(
                    $this->getKey(),
                    $attribute->id,
                    $ids
                );
            }

            $this->unsetRelation('attributeOptions');

            return true;
        }

        // PAV
        $pav = $this->attributeValues()->firstOrNew(['attribute_id' => $attribute->id]);
        $pav->setTypedValue($attribute, $value);
        $pav->attribute()->associate($attribute);
        $pav->save();

        $this->unsetRelation('attributeValues');

        return $pav;
    }

    /**
     * Сформировать текстовую метку значения(й) атрибута с учётом единиц,
     * округления и диапазонов. Удобно для карточки товара/списков.
     *
     * @param  string|int  $attr  slug или id атрибута
     * @param  string  $separator  разделитель для мультизначений
     */
    public function attrLabel(
        string|int|AttributeDef $attr,
        string $separator = ' / ',
        ?Category $category = null,
    ): ?string {
        // 1) Получаем объект атрибута без лишних запросов
        if ($attr instanceof AttributeDef) {
            $attribute = $attr->loadMissing('unit');
        } else {
            $attribute = is_numeric($attr)
                ? AttributeDef::with('unit')->find((int) $attr)
                : AttributeDef::with('unit')->where('slug', $attr)->first();
        }

        if (! $attribute) {
            return null;
        }

        // Единица отображения для конкретной категории
        // Единица отображения для конкретной категории
        $displayUnit = $attribute->uiUnitForCategory($category);
        $unitSuffix = $displayUnit?->symbol ? (' '.$displayUnit->symbol) : '';

        // Форматтер числа с учётом категории
        $fmt = function (float $ui) use ($attribute, $category): string {
            $dec = $attribute->filterNumberDecimalsForCategory($category);
            $uiQ = $attribute->quantizeForCategory($ui, $category);

            $str = number_format($uiQ, $dec, '.', '');

            if ($dec > 0 && str_contains($str, '.')) {
                $str = rtrim(rtrim($str, '0'), '.');
            }

            return $str === '' ? '0' : $str;
        };

        // === select / multiselect ===
        if ($attribute->usesOptions()) {
            if ($this->relationLoaded('attributeOptions')) {
                $rows = $this->attributeOptions
                    ->where('pivot.attribute_id', $attribute->getKey())
                    ->sortBy('sort_order');

                $labels = $rows->pluck('value')->values()->all();
            } else {
                $labels = $this->attributeOptions()
                    ->where('attribute_options.attribute_id', $attribute->getKey())
                    ->orderBy('attribute_options.sort_order')
                    ->pluck('attribute_options.value')
                    ->all();
            }

            return $labels ? implode($separator, $labels) : null;
        }

        // === PAV (text/number/boolean/range) ===
        $rows = $this->relationLoaded('attributeValues')
            ? $this->attributeValues->where('attribute_id', $attribute->getKey())
            : $this->attributeValues()->where('attribute_id', $attribute->getKey())->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $type = $attribute->data_type; // 'text' | 'number' | 'boolean' | 'range'

        $labels = $rows->map(function ($r) use ($attribute, $fmt, $unitSuffix, $type, $displayUnit) {
            // === RANGE ===
            if ($type === 'range') {
                $hasRange = ($r->value_min_si !== null || $r->value_max_si !== null)
                    || ($r->value_min !== null || $r->value_max !== null);

                if ($hasRange) {
                    $minSi = $r->value_min_si
                        ?? ($r->value_min !== null ? $attribute->toSi((float) $r->value_min) : null);
                    $maxSi = $r->value_max_si
                        ?? ($r->value_max !== null ? $attribute->toSi((float) $r->value_max) : null);

                    $minUi = $minSi !== null
                        ? $attribute->fromSiWithUnit((float) $minSi, $displayUnit)
                        : null;
                    $maxUi = $maxSi !== null
                        ? $attribute->fromSiWithUnit((float) $maxSi, $displayUnit)
                        : null;

                    if ($minUi !== null && $maxUi !== null) {
                        return $fmt($minUi).'—'.$fmt($maxUi).$unitSuffix;
                    }
                    if ($minUi !== null) {
                        return '≥ '.$fmt($minUi).$unitSuffix;
                    }
                    if ($maxUi !== null) {
                        return '≤ '.$fmt($maxUi).$unitSuffix;
                    }
                }

                if ($r->value_si !== null || $r->value_number !== null) {
                    $si = $r->value_si ?? $attribute->toSi((float) $r->value_number);
                    $ui = $attribute->fromSiWithUnit((float) $si, $displayUnit);

                    return $fmt($ui).$unitSuffix;
                }

                return null;
            }

            // === NUMBER ===
            if ($type === 'number') {
                if ($r->value_si !== null || $r->value_number !== null) {
                    $si = $r->value_si ?? $attribute->toSi((float) $r->value_number);
                    $ui = $attribute->fromSiWithUnit((float) $si, $displayUnit);

                    return $fmt($ui).$unitSuffix;
                }

                $hasRange = ($r->value_min_si !== null || $r->value_max_si !== null)
                    || ($r->value_min !== null || $r->value_max !== null);

                if ($hasRange) {
                    $minSi = $r->value_min_si
                        ?? ($r->value_min !== null ? $attribute->toSi((float) $r->value_min) : null);
                    $maxSi = $r->value_max_si
                        ?? ($r->value_max !== null ? $attribute->toSi((float) $r->value_max) : null);

                    $si = $minSi ?? $maxSi;
                    if ($si !== null) {
                        $ui = $attribute->fromSiWithUnit((float) $si, $displayUnit);

                        return $fmt($ui).$unitSuffix;
                    }
                }

                return null;
            }

            // === BOOLEAN ===
            if ($type === 'boolean') {
                if ($r->value_boolean !== null) {
                    return $r->value_boolean ? 'Да' : 'Нет';
                }

                return null;
            }

            // === TEXT / fallback ===
            if ($r->value_text !== null && trim($r->value_text) !== '') {
                return (string) $r->value_text;
            }

            if ($r->value_si !== null || $r->value_number !== null) {
                $si = $r->value_si ?? (float) $r->value_number;
                $ui = $attribute->fromSiWithUnit((float) $si, $displayUnit);

                return $fmt($ui).$unitSuffix;
            }

            if ($r->value_boolean !== null) {
                return $r->value_boolean ? 'Да' : 'Нет';
            }

            return null;
        })->filter()->values()->all();

        return $labels ? implode($separator, $labels) : null;
    }

    /**
     * Проверка: обязателен ли атрибут для товара.
     *
     * Если $categoryId передан — проверяем строго в контексте этой категории.
     * Если не передан — считаем, что «обязателен, если обязателен хотя бы
     * в одной категории товара».
     */
    public function isAttributeRequired(string|int $attr, ?int $categoryId = null): bool
    {
        $attrId = is_numeric($attr)
            ? (int) $attr
            : AttributeDef::where('slug', $attr)->value('id');

        if (! $attrId) {
            return false;
        }

        $q = DB::table('category_attribute as ca')
            ->join('product_categories as pc', 'pc.category_id', '=', 'ca.category_id')
            ->where('pc.product_id', $this->id)
            ->where('ca.attribute_id', $attrId);

        if ($categoryId) {
            $q->where('ca.category_id', $categoryId);
        }

        return (int) $q->max('ca.is_required') === 1;
    }

    /**
     * IDs атрибутов, которые реально заполнены для товара, с учётом типа атрибута:
     * - select/multiselect → есть запись(и) в product_attribute_option
     * - boolean           → есть PAV с value_boolean IS NOT NULL (0 — допустимо)
     * - number            → есть PAV с value_si или value_number IS NOT NULL
     * - text              → есть PAV с непустым TRIM(value_text)
     * - range             → есть PAV с (value_min_si|value_max_si|value_min|value_max) IS NOT NULL
     *
     * @param  array<int,int>|null  $limitAttributeIds  Если переданы — проверяем только эти ID
     * @return array<int,int>
     */
    public function filledAttributeIds(?array $limitAttributeIds = null): array
    {
        // SELECT/MULTISELECT — только для атрибутов с input_type in (...)
        $opt = DB::table('product_attribute_option as pao')
            ->join('attributes as a', 'a.id', '=', 'pao.attribute_id')
            ->where('pao.product_id', $this->id)
            ->whereIn('a.input_type', ['select', 'multiselect'])
            ->when($limitAttributeIds, fn ($q) => $q->whereIn('pao.attribute_id', $limitAttributeIds))
            ->groupBy('pao.attribute_id')
            ->pluck('pao.attribute_id')
            ->all();

        // PAV — в зависимости от data_type
        $pav = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $this->id)
            ->when($limitAttributeIds, fn ($q) => $q->whereIn('pav.attribute_id', $limitAttributeIds))
            ->where(function ($q) {
                // boolean: 0/1 — валидно, важно только NOT NULL
                $q->where(function ($q) {
                    $q->where('a.data_type', 'boolean')
                        ->whereNotNull('pav.value_boolean');
                })
                    // number: есть value_si или value_number
                    ->orWhere(function ($q) {
                        $q->where('a.data_type', 'number')
                            ->where(function ($q) {
                                $q->whereNotNull('pav.value_si')
                                    ->orWhereNotNull('pav.value_number');
                            });
                    })
                    // text: непустой TRIM(value_text)
                    ->orWhere(function ($q) {
                        $q->where('a.data_type', 'text')
                            ->whereNotNull('pav.value_text')
                            ->whereRaw("TRIM(pav.value_text) <> ''");
                    })
                    // range: есть любая из границ (si или не si)
                    ->orWhere(function ($q) {
                        $q->where('a.data_type', 'range')
                            ->where(function ($q) {
                                $q->whereNotNull('pav.value_min_si')
                                    ->orWhereNotNull('pav.value_max_si')
                                    ->orWhereNotNull('pav.value_min')
                                    ->orWhereNotNull('pav.value_max');
                            });
                    });
            })
            ->groupBy('pav.attribute_id')
            ->pluck('pav.attribute_id')
            ->all();

        $filled = array_unique(array_map('intval', array_merge($opt, $pav)));
        sort($filled);

        return $filled;
    }

    public function requiredAttributeIds(?int $categoryId = null): array
    {
        $q = DB::table('category_attribute as ca')
            ->join('product_categories as pc', 'pc.category_id', '=', 'ca.category_id')
            ->where('pc.product_id', $this->id)
            ->where('ca.is_required', 1);

        if ($categoryId) {
            $q->where('ca.category_id', $categoryId);
        }

        return $q->pluck('ca.attribute_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Недостающие обязательные атрибуты (id, name, slug, input_type, data_type).
     */
    public function missingRequiredAttributes(?int $categoryId = null): Collection
    {
        $req = $this->requiredAttributeIds($categoryId);
        if (! $req) {
            return collect();
        }

        $filled = $this->filledAttributeIds($req);
        $missingIds = array_values(array_diff($req, $filled));

        return AttributeDef::query()
            ->whereIn('id', $missingIds)
            ->get(['id', 'name', 'slug', 'input_type', 'data_type']);
    }

    private function toLatin(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        // Если есть intl — используем максимально корректную транслитерацию
        if (function_exists('transliterator_transliterate')) {
            // Any-Latin → Latin-ASCII → убираем диакритику → в нижний регистр
            $latin = transliterator_transliterate('Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $text);
        } else {
            // Фолбэк Laravel (portable-ascii)
            $latin = Str::ascii($text);
            $latin = Str::lower($latin);
        }

        // Нормализуем пробелы
        $latin = trim(preg_replace('/\s+/u', ' ', $latin));

        return $latin;
    }

    /**
     * Индексируем алиасы кодов вида W0201 / JWP-201, чтобы находились запросы без буквенного префикса.
     *
     * @return array<int, string>
     */
    private function buildSearchTerms(string ...$values): array
    {
        $terms = [];

        foreach ($values as $value) {
            $latinValue = $this->toLatin($value);

            if ($latinValue === '') {
                continue;
            }

            preg_match_all('/[a-z0-9-]+/u', $latinValue, $matches);

            foreach ($matches[0] as $token) {
                $token = trim($token, '-');

                if ($token === '' || preg_match('/\d/', $token) !== 1) {
                    continue;
                }

                if (preg_match('/^[a-z]+-?\d+[a-z0-9-]*$/', $token) !== 1) {
                    continue;
                }

                $normalizedToken = str_replace('-', '', $token);
                $suffixWithoutPrefix = preg_replace('/^[a-z]+-?/', '', $token) ?? '';
                $normalizedSuffix = str_replace('-', '', $suffixWithoutPrefix);
                $digitsOnly = preg_replace('/\D+/', '', $suffixWithoutPrefix) ?? '';

                foreach ([$normalizedToken, $normalizedSuffix] as $term) {
                    if ($term === '' || mb_strlen($term) < 3) {
                        continue;
                    }

                    $terms[$term] = $term;
                }

                if ($digitsOnly !== '' && mb_strlen($digitsOnly) >= 3) {
                    $terms[$digitsOnly] = $digitsOnly;
                }
            }
        }

        return array_values($terms);
    }
}
