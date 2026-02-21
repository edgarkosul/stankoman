<?php

namespace App\Livewire\Pages\Favorites;

use App\Livewire\Header\FavoritesBadge;
use App\Models\Product;
use App\Support\FavoritesService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public int $perPage = 24;

    #[Url(history: true)]
    public string $q = '';

    #[Url(history: true)]
    public string $sort = 'popular';

    #[On('favorites:list-updated')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearAll(FavoritesService $favorites): void
    {
        $favorites->clear();

        $this->resetPage();

        $this->dispatch('favorites:list-updated', ids: []);
        $this->dispatch('favorites:updated', count: 0)
            ->to(FavoritesBadge::class);
    }

    public function removeFavorite(int $productId, FavoritesService $favorites): void
    {
        $ids = $favorites->remove($productId);

        $this->resetPage();

        $this->dispatch('favorites:list-updated', ids: $ids);
        $this->dispatch('favorites:updated', count: count($ids))
            ->to(FavoritesBadge::class);
    }

    public function render(FavoritesService $favorites): View
    {
        $ids = $favorites->ids();

        if ($ids === []) {
            $products = Product::query()
                ->whereRaw('1=0')
                ->paginate($this->perPage);

            return view('livewire.pages.favorites.index', compact('products'))
                ->layout('layouts.catalog', ['title' => 'Избранные товары']);
        }

        if (Auth::check()) {
            $query = Product::query()
                ->select('products.*')
                ->join('favorite_products as fp', 'fp.product_id', '=', 'products.id')
                ->where('fp.user_id', Auth::id());
        } else {
            $idsList = implode(',', array_map('intval', $ids));

            $query = Product::query()
                ->whereIn('products.id', $ids)
                ->orderByRaw("FIELD(products.id, {$idsList})");
        }

        if ($this->q !== '') {
            $search = trim($this->q);

            $query->where(function ($subQuery) use ($search): void {
                $subQuery->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.slug', 'like', "%{$search}%");
            });
        }

        $query = match ($this->sort) {
            'price_asc' => $query->orderBy('products.price_amount')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('products.price_amount')->orderBy('products.id'),
            'new' => $query->latest('products.id'),
            default => $query->orderByDesc('products.popularity')->orderBy('products.id'),
        };

        $products = $query->paginate($this->perPage)->withQueryString();

        return view('livewire.pages.favorites.index', compact('products'))
            ->layout('layouts.catalog', ['title' => 'Избранные товары']);
    }
}
