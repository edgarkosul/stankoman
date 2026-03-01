<?php

namespace App\Livewire;

use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class RecentProductsSlider extends Component
{
    public Collection $products;

    public function mount(): void
    {
        $this->products = collect();
    }

    /** @param array<int,int|string> $ids */
    public function load(array $ids): void
    {
        $ids = collect($ids)
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->take(20)
            ->values();

        if ($ids->isEmpty()) {
            $this->products = collect();

            return;
        }

        $models = Product::query()
            ->where('is_active', true)
            ->whereIn('id', $ids->all())
            ->with([
                'categories' => fn ($query) => $query
                    ->wherePivot('is_primary', true)
                    ->with('attributeDefs.unit'),
                'attributeValues.attribute.unit',
                'attributeOptions.attribute.unit',
            ])
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

        $order = $ids->flip();
        $this->products = $models
            ->sortBy(fn (Product $product): int => (int) ($order[$product->id] ?? PHP_INT_MAX))
            ->values();
    }

    public function render(): View
    {
        return view('livewire.recent-products-slider');
    }
}
