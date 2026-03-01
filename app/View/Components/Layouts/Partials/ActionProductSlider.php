<?php

namespace App\View\Components\Layouts\Partials;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class ActionProductSlider extends Component
{
    public Collection $products;

    public function __construct()
    {
        $this->products = Product::query()
            ->where('is_active', true)
            ->where('discount_price', '>', 0)
            ->with([
                'categories' => fn ($query) => $query
                    ->wherePivot('is_primary', true)
                    ->with('attributeDefs.unit'),
            ])
            ->orderByDesc('updated_at')
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
            ]);
    }

    public function render(): View|Closure|string
    {
        return view('components.layouts.partials.action-product-slider');
    }
}
