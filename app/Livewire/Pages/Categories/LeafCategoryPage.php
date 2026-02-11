<?php

namespace App\Livewire\Pages\Categories;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductFilterService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LeafCategoryPage extends Component
{
    use WithPagination;

    public string $path = '';

    #[Url(as: 'q')]
    public ?string $q = null;

    #[Url(as: 'f')]
    public array $filters = [];

    #[Url(as: 'sort')]
    public string $sort = 'popular';

    public ?Category $category = null;

    public function mount(?string $path = null): void
    {
        $this->path = trim($path ?? '', '/');

        if ($this->path === '') {
            return;
        }

        $this->category = $this->resolveCategoryFromPath($this->path);
    }

    public function updatingQ(): void
    {
        $this->resetPage();
    }

    public function updatingFilters(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    protected function resolveCategoryFromPath(string $path): Category
    {
        $slugs = array_values(array_filter(explode('/', $path), fn(string $slug) => $slug !== ''));

        $parentId = Category::defaultParentKey();
        $category = null;

        foreach ($slugs as $slug) {
            $category = Category::query()
                ->where('parent_id', $parentId)
                ->where('slug', $slug)
                ->firstOrFail();

            $parentId = $category->getKey();
        }

        return $category;
    }

    public function categoryPlural(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'категория';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'категории';
        }

        return 'категорий';
    }

    public function productPlural(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'товар';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'товара';
        }

        return 'товаров';
    }

    public function render()
    {
        if (! $this->category) {
            $subcategories = Category::query()
                ->where('parent_id', Category::defaultParentKey())
                ->with(['children' => fn($q) => $q
                    ->select(['id', 'name', 'slug', 'parent_id'])
                    ->orderBy('order')])
                ->orderBy('order')
                ->get(['id', 'name', 'slug', 'img', 'parent_id']);

            return view('pages.categories.root', [
                'category' => null,
                'subcategories' => $subcategories,
            ])->layout('layouts.catalog', [
                'title' => 'Каталог',
            ]);
        }

        if ($this->category->children()->exists()) {
            $subcategories = $this->category->children()
                ->with(['children' => fn($q) => $q
                    ->select(['id', 'name', 'slug', 'parent_id'])
                    ->orderBy('order')])
                ->select(['id', 'name', 'slug', 'img', 'parent_id'])
                ->withCount([
                    'products as products_count' => fn($q) => $q->where('is_active', true),
                ])
                ->orderBy('order')
                ->get();

            return view('pages.categories.branch', [
                'category' => $this->category,
                'subcategories' => $subcategories,
            ])->layout('layouts.catalog', [
                'title' => $this->category->name,
            ]);
        }

        $query = Product::query()
            ->select([
                'id',
                'name',
                'slug',
                'price_amount',
                'discount_price',
                'image',
                'thumb',
                'gallery',
                'popularity',
                'sku',
            ])
            ->where('is_active', true)
            ->whereHas('categories', fn($q) => $q->whereKey($this->category->getKey()));

        if ($this->q) {
            $query->where('name', 'like', '%' . trim($this->q) . '%');
        }

        $query = ProductFilterService::apply($query, $this->filters, $this->category);

        $query = match ($this->sort) {
            'price_asc' => $query->orderBy('price_amount')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price_amount')->orderBy('id'),
            'new' => $query->orderByDesc('id'),
            default => $query->orderByDesc('popularity')->orderBy('id'),
        };

        $products = $query->paginate(24);

        $filtersSchema = ProductFilterService::schemaForCategory($this->category)
            ->map
            ->toArray()
            ->values()
            ->all();

        return view('livewire.pages.categories.leaf', [
            'category' => $this->category,
            'products' => $products,
            'filtersSchema' => $filtersSchema,
        ])->layout('layouts.catalog', [
            'title' => $this->category->name,
        ]);
    }
}
