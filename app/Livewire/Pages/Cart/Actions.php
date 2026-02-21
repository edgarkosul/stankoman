<?php

namespace App\Livewire\Pages\Cart;

use App\Models\Product;
use App\Support\CartService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Actions extends Component
{
    public int $productId;

    public int $qty = 1;

    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    public string $variant = '';

    public bool $extended = false;

    public bool $inCart = false;

    public ?string $tooltip = null;

    public bool $isInStock = true;

    public bool $isPrice = true;

    public function mount(CartService $cart): void
    {
        if ($this->variant !== 'leaf') {
            $this->extended = true;
        }

        $product = Product::query()
            ->select(['id', 'in_stock', 'price_amount'])
            ->find($this->productId);

        $this->isInStock = (bool) ($product?->in_stock ?? false);
        $this->isPrice = (bool) ($product?->price_amount ?? 0) > 0;

        $this->inCart = $cart->isInCart($this->productId, options: null, strictOptions: false);
        $this->setTooltip();
    }

    public function add(CartService $cart): void
    {
        if (! $this->isInStock || ! $this->isPrice) {
            return;
        }

        $cart->addItem($this->productId, $this->qty, $this->options);

        $this->inCart = true;
        $this->setTooltip();

        $this->dispatch('cart:updated', count: $this->count($cart));
        $this->dispatch('cart:added', productId: $this->productId);
    }

    public function remove(CartService $cart): void
    {
        $cart->removeItem($this->productId, $this->options);

        $this->inCart = false;
        $this->setTooltip();

        $this->dispatch('cart:updated', count: $this->count($cart));
    }

    public function setQty(CartService $cart): void
    {
        $quantity = max(0, (int) $this->qty);
        $cart->updateQuantity($this->productId, $quantity, $this->options);

        $this->inCart = $quantity > 0;
        $this->setTooltip();

        $this->dispatch('cart:updated', count: $this->count($cart));
    }

    #[On('cart:updated')]
    public function sync(CartService $cart): void
    {
        $this->inCart = $cart->isInCart($this->productId, options: null, strictOptions: false);
        $this->setTooltip();
    }

    protected function count(CartService $cart): int
    {
        return $cart->uniqueProductsCount();
    }

    protected function setTooltip(): void
    {
        if ($this->inCart) {
            $this->tooltip = 'Уже в корзине';

            return;
        }

        if (! $this->isInStock) {
            $this->tooltip = 'Нет в наличии';

            return;
        }

        if (! $this->isPrice) {
            $this->tooltip = 'Запросите цену';

            return;
        }

        $this->tooltip = 'Добавить в корзину';
    }

    public function render(): View
    {
        return view('livewire.pages.cart.actions');
    }
}
