<?php

namespace App\Livewire\Pages\Cart;

use App\Support\CartService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Icon extends Component
{
    public int $count = 0;

    public function mount(CartService $cart): void
    {
        $this->count = $cart->uniqueProductsCount();
    }

    #[On('cart:updated')]
    public function refreshCount(?int $count = null): void
    {
        $this->count = $count ?? app(CartService::class)->uniqueProductsCount();
    }

    public function goToCart(): void
    {
        if (! app(CartService::class)->isEmpty()) {
            $this->redirectRoute('cart.index');
        }
    }

    public function render(): View
    {
        return view('livewire.pages.cart.icon');
    }
}
