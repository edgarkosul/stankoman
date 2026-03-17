<?php

namespace App\View\Components\Product;

use App\Models\Category;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Popular extends Component
{
    public Collection $products;

    public function __construct(
        public ?Category $category = null,
        public ?Product $product = null,
    ) {
        $this->products = $this->resolveProducts();
    }

    public function render(): View|Closure|string
    {
        return view('components.product.popular');
    }

    public function shouldRender(): bool
    {
        return $this->products->count() >= 5;
    }

    private function resolveProducts(): Collection
    {
        $query = Product::query()
            ->where('is_active', true)
            ->with([
                'categories' => fn ($query) => $query
                    ->wherePivot('is_primary', true)
                    ->with('attributeDefs.unit'),
                'attributeValues.attribute.unit',
                'attributeOptions.attribute.unit',
            ]);

        if ($this->product) {
            $query->whereKeyNot($this->product->getKey());
        }

        $scopeCategoryIds = $this->scopeCategoryIds();

        if ($scopeCategoryIds !== []) {
            $query->whereHas('categories', function (Builder $builder) use ($scopeCategoryIds): void {
                $builder->whereIn('categories.id', $scopeCategoryIds);
            });
        }

        return $query
            ->orderByDesc('popularity')
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id',
                'name',
                'slug',
                'sku',
                'price_amount',
                'discount_price',
                'image',
                'thumb',
                'gallery',
                'popularity',
                'is_active',
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function scopeCategoryIds(): array
    {
        $category = $this->resolveContextCategory();

        if (! $category) {
            return [];
        }

        $categories = Category::query()
            ->select(['id', 'parent_id'])
            ->where('is_active', true)
            ->orderBy('parent_id')
            ->get()
            ->groupBy('parent_id');

        $categoryIds = [];
        $visited = [];
        $stack = [(int) $category->getKey()];

        while ($stack !== []) {
            $currentId = array_pop($stack);

            if (! is_int($currentId) || isset($visited[$currentId])) {
                continue;
            }

            $visited[$currentId] = true;
            $categoryIds[] = $currentId;

            foreach ($categories->get($currentId, collect()) as $child) {
                $stack[] = (int) $child->getKey();
            }
        }

        return $categoryIds;
    }

    private function resolveContextCategory(): ?Category
    {
        if ($this->category) {
            return $this->category;
        }

        if (! $this->product) {
            return null;
        }

        if ($this->product->relationLoaded('categories')) {
            return $this->product->primaryCategory() ?? $this->product->categories->first();
        }

        return $this->product->primaryCategory() ?? $this->product->categories()->first();
    }
}
