<?php

namespace App\Models;

use App\Models\Attribute as AttributeDef;
use App\Models\Pivots\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use SolutionForest\FilamentTree\Concern\ModelTree;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $img
 * @property bool $is_active
 * @property int $parent_id
 * @property int $order
 * @property string|null $meta_description
 * @property array<array-key, mixed>|null $meta_json
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CategoryAttribute|null $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AttributeDef> $attributeDefs
 * @property-read int|null $attribute_defs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Category> $children
 * @property-read int|null $children_count
 * @property-read string|null $image_url
 * @property-read Category|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property-read mixed $slug_path
 *
 * @method static Builder<static>|Category availableAsParent()
 * @method static Builder<static>|Category active()
 * @method static Builder<static>|Category isRoot()
 * @method static Builder<static>|Category leaf()
 * @method static Builder<static>|Category newModelQuery()
 * @method static Builder<static>|Category newQuery()
 * @method static Builder<static>|Category ordered(string $direction = 'asc')
 * @method static Builder<static>|Category query()
 * @method static Builder<static>|Category roots()
 * @method static Builder<static>|Category whereCreatedAt($value)
 * @method static Builder<static>|Category whereId($value)
 * @method static Builder<static>|Category whereImg($value)
 * @method static Builder<static>|Category whereIsActive($value)
 * @method static Builder<static>|Category whereMetaJson($value)
 * @method static Builder<static>|Category whereName($value)
 * @method static Builder<static>|Category whereOrder($value)
 * @method static Builder<static>|Category whereParentId($value)
 * @method static Builder<static>|Category whereSlug($value)
 * @method static Builder<static>|Category whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Category extends Model
{
    use ModelTree;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'img',
        'is_active',
        'order',
        'meta_description',
        'meta_json',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'meta_json' => 'array',
    ];

    protected $appends = ['image_url', 'slug_path'];

    protected ?string $cachedSlugPath = null;

    // ===== Связи =====
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories')
            ->using(ProductCategory::class)
            ->withPivot('is_primary');
    }

    public function attributeDefs(): BelongsToMany
    {
        return $this->belongsToMany(AttributeDef::class, 'category_attribute')
            ->using(CategoryAttribute::class)
            ->withPivot([
                'is_required',
                'filter_order',
                'compare_order',
                'visible_in_specs',
                'visible_in_compare',
                'display_unit_id',
                'number_decimals',
                'number_step',
                'number_rounding',
            ])
            ->withTimestamps()
            ->orderByPivot('filter_order');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public static function defaultParentKey(): int
    {
        return -1; // root
    }

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    // ===== Скоупы =====

    public function scopeRoots(Builder $q): Builder
    {
        return $q->where('parent_id', -1)->orderBy('order');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeLeaf(Builder $q): Builder
    {
        return $q->whereDoesntHave('children');
    }

    public function scopeAvailableAsParent(Builder $q): Builder
    {
        return $q->where(function (Builder $query): void {
            $query
                ->whereHas('children')
                ->orWhereDoesntHave('products');
        });
    }

    // ===== Навигация =====

    /** Коллекция: [корень, ..., текущая] */
    public function ancestorsAndSelf()
    {
        $ancestors = collect();
        $node = $this;
        while ($node) {
            $ancestors->prepend($node);
            $node = $node->parent_id === -1 ? null : $node->parent;
        }

        return $ancestors;
    }

    /** Корневая категория для текущего узла */
    public function root(): self
    {
        /** @var \App\Models\Category $root */
        $root = $this->ancestorsAndSelf()->first();

        return $root;
    }

    /** Полный путь slug’ов: "kompressory/bezmaslyanye" */
    public function slugPath(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($this->cachedSlugPath !== null) {
                    return $this->cachedSlugPath;
                }

                return $this->cachedSlugPath = $this->ancestorsAndSelf()
                    ->pluck('slug')
                    ->implode('/');
            }
        );
    }

    // ===== Атрибуты =====

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->img;
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $disk->url($path);
    }

    public function getUniqueBrands(): Collection
    {
        return $this->products->where('is_active', true)->pluck('brand')->unique()->values()->filter();
    }

    // ===== Хуки =====

    protected static function booted(): void
    {
        // удаляем старый файл при замене
        static::updating(function (Category $m) {
            if ($m->isDirty('img')) {
                $old = $m->getOriginal('img');
                if ($old && ! str_starts_with($old, 'http')) {
                    Storage::disk('public')->delete($old);
                }
            }
        });

        // удаляем файл при удалении записи
        static::deleting(function (Category $m) {
            if ($m->img && ! str_starts_with($m->img, 'http')) {
                Storage::disk('public')->delete($m->img);
            }
        });

        static::saved(function () {
            Cache::forget('catalog.menu.v1');
        });

        static::deleted(function () {
            Cache::forget('catalog.menu.v1');
        });

    }

    public function isBrandCategory(): bool
    {
        $node = $this;
        while ($node) {
            if ($node->slug === 'vybor-po-proizvoditelyu') {
                return true;
            }
            $node = $node->parent;
        }

        return false;
    }

    public function filterableAttributes()
    {
        return $this->attributeDefs()
            ->where('attributes.is_filterable', true)
            ->orderBy('filter_order');
    }

    public function ui(?string $key = null, $default = null)
    {
        $ui = data_get($this->meta_json, 'ui', []);

        return $key ? data_get($ui, $key, $default) : $ui;
    }
}
