<?php

namespace App\Livewire\Header;

use App\Support\FavoritesService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class FavoritesBadge extends Component
{
    public int $count = 0;

    public function mount(FavoritesService $favorites): void
    {
        $this->count = $favorites->count();
    }

    public function goToFavoritesPage(): void
    {
        if ($this->count > 0) {
            $this->redirectRoute('favorites.index');
        }
    }

    #[On('favorites:updated')]
    public function refresh(?int $count = null): void
    {
        $this->count = $count ?? app(FavoritesService::class)->count();
    }

    public function render(): View
    {
        return view('livewire.header.favorites-badge');
    }
}
