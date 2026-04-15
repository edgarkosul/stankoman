<?php

namespace App\Livewire\Pages\Cart;

use App\Support\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;

class Icon extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->count = $this->resolveCount();
    }

    #[On('cart:updated')]
    public function refreshCount(?int $count = null): void
    {
        $this->count = $count ?? $this->resolveCount();
    }

    public function goToCart(): void
    {
        if (! $this->supportsPersistentCart()) {
            return;
        }

        if (! app(CartService::class)->isEmpty()) {
            $this->redirectRoute('cart.index');
        }
    }

    protected function resolveCount(): int
    {
        if (! $this->supportsPersistentCart()) {
            return 0;
        }

        return app(CartService::class)->uniqueProductsCount();
    }

    protected function supportsPersistentCart(): bool
    {
        return Schema::hasTable('carts') && Schema::hasTable('cart_items');
    }

    public function render(): View
    {
        return view('livewire.pages.cart.icon');
    }
}
