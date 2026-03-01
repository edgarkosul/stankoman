<?php

namespace App\View\Components\Product;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Similar extends Component
{
    public Collection $products;

    public function __construct(
        public Product $product,
    ) {
        $this->products = $this->resolveProducts();
    }

    public function render(): View|Closure|string
    {
        return view('components.product.similar');
    }

    private function resolveProducts(): Collection
    {
        $categoryIds = $this->product->relationLoaded('categories')
            ? $this->product->categories->pluck('id')
            : $this->product->categories()->pluck('categories.id');

        $categoryIds = $categoryIds
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {
            return collect();
        }

        $productsQuery = Product::query()
            ->where('is_active', true)
            ->whereKeyNot($this->product->getKey())
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categoryIds->all()))
            ->with([
                'categories' => fn ($query) => $query
                    ->wherePivot('is_primary', true)
                    ->with('attributeDefs.unit'),
                'attributeValues.attribute.unit',
                'attributeOptions.attribute.unit',
            ])
            ->select([
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

        $referencePrice = max(0, (int) $this->product->price_final);

        if ($referencePrice > 0) {
            $productsQuery
                ->orderByRaw(
                    'ABS(CAST((CASE WHEN discount_price IS NOT NULL AND discount_price > 0 AND discount_price < price_amount THEN discount_price ELSE price_amount END) AS SIGNED) - CAST(? AS SIGNED)) ASC',
                    [$referencePrice]
                )
                ->inRandomOrder();
        } else {
            $productsQuery->inRandomOrder();
        }

        return $productsQuery
            ->limit(10)
            ->get();
    }
}
