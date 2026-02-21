<?php

namespace App\Livewire\Pages\Product;

use App\Livewire\Header\FavoritesBadge;
use App\Support\FavoritesService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class FavoriteToggle extends Component
{
    public int $productId;

    public bool $added = false;

    public string $variant = '';

    public ?string $tooltip = null;

    public function mount(int $productId, FavoritesService $favorites): void
    {
        $this->productId = $productId;
        $this->added = $favorites->contains($productId);
        $this->setTooltip();
    }

    public function toggle(FavoritesService $favorites): void
    {
        $ids = $favorites->toggle($this->productId);

        $this->added = in_array($this->productId, $ids, true);

        $this->dispatch('favorites:list-updated', ids: $ids);
        $this->dispatch('favorites:updated', count: count($ids))
            ->to(FavoritesBadge::class);

        $this->setTooltip();
    }

    #[On('favorites:list-updated')]
    public function sync(FavoritesService $favorites): void
    {
        $this->added = $favorites->contains($this->productId);
        $this->setTooltip();
    }

    public function render(): View
    {
        return view('livewire.pages.product.favorite-toggle');
    }

    protected function setTooltip(): void
    {
        $this->tooltip = $this->added
            ? 'Убрать из избранного'
            : 'Добавить в избранное';
    }
}
