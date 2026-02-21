<?php

namespace App\Livewire\Pages\Compare;

use App\Models\Product;
use App\Support\CompareMatrixBuilder;
use App\Support\CompareService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Page extends Component
{
    /**
     * @var array{attributes?: array<int, array<string, mixed>>, products?: array<int, array<string, mixed>>}
     */
    public array $vm = [];

    public bool $diff = false;

    public bool $nonempty = false;

    public function mount(): void
    {
        $this->applyFilters();
    }

    #[On('compare:list-updated')]
    public function sync(): void
    {
        $this->applyFilters();
    }

    public function clear(): void
    {
        $compare = app(CompareService::class);
        $compare->clear();

        $this->applyFilters();

        $this->dispatch('compare:list-updated');
        $this->dispatch('compare:updated', count: 0);
    }

    public function showAll(): void
    {
        $this->diff = false;
        $this->nonempty = false;

        $this->applyFilters();
    }

    public function showDiff(): void
    {
        $this->diff = true;

        $this->applyFilters();
    }

    public function updatedNonempty(): void
    {
        $this->applyFilters();
    }

    public function removeItem(int $productId): void
    {
        $compare = app(CompareService::class);
        $ids = $compare->remove($productId);

        $this->applyFilters();

        $this->dispatch('compare:list-updated', ids: $ids);
        $this->dispatch('compare:updated', count: count($ids));
    }

    public function applyFilters(): void
    {
        $compare = app(CompareService::class);
        $builder = app(CompareMatrixBuilder::class);

        $ids = $compare->ids();

        $products = Product::query()
            ->select([
                'id',
                'name',
                'slug',
                'price_amount',
                'image',
                'sku',
                'brand',
            ])
            ->with([
                'categories' => fn ($query) => $query->select('categories.id', 'name'),
            ])
            ->whereIn('id', $ids)
            ->get();

        if ($ids !== []) {
            $products = $products
                ->sortBy(function (Product $product) use ($ids): int {
                    $position = array_search($product->id, $ids, true);

                    return $position === false ? PHP_INT_MAX : $position;
                })
                ->values();
        }

        $this->vm = $builder->build($products, [
            'hideEquals' => $this->diff,
            'hideEmpty' => $this->nonempty,
        ]);

        $this->dispatch('compare:equalize');
    }

    public function render(): View
    {
        return view('livewire.pages.compare.page')
            ->layout('layouts.catalog', ['title' => 'Сравнение товаров']);
    }
}
