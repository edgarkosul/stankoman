<?php

namespace App\Livewire\Pages\Cart;

use App\Models\CartItem;
use App\Support\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    /**
     * @var \Illuminate\Support\Collection<int, array{
     *     cart_item_id:int,
     *     id:int,
     *     name:string,
     *     slug:string|null,
     *     image:string|null,
     *     price:float,
     *     has_discount:bool,
     *     with_dns:string,
     *     qty:int,
     *     subtotal:float,
     *     line_total:float,
     *     url:string|null
     * }>
     */
    public Collection $rows;

    public int $totalQty = 0;

    public float $totalSum = 0.0;

    public float $discTotalSum = 0.0;

    public float $discountSum = 0.0;

    public function mount(CartService $cart): void
    {
        $this->loadData($cart);
    }

    public function clear(CartService $cart): void
    {
        $cart->clear();

        $this->loadData($cart);
        $this->dispatch('cart:updated', count: 0);
    }

    public function incItem(int $cartItemId, CartService $cart): void
    {
        $item = $cart->getCart()->items()->whereKey($cartItemId)->first();

        if (! $item instanceof CartItem) {
            return;
        }

        $item->increment('quantity');

        $this->afterQtyChange($cart);
    }

    public function decOrSoftRemove(int $cartItemId, CartService $cart): void
    {
        $item = $cart->getCart()->items()->whereKey($cartItemId)->first();

        if (! $item instanceof CartItem) {
            return;
        }

        if ((int) $item->quantity > 1) {
            $item->decrement('quantity');
            $this->afterQtyChange($cart);

            return;
        }

        $this->dispatch('cart:soft-remove', id: $item->id);
    }

    public function finalizeRemove(int $cartItemId, CartService $cart): void
    {
        $item = $cart->getCart()->items()->whereKey($cartItemId)->first();

        if ($item instanceof CartItem) {
            $item->delete();
        }

        $this->afterQtyChange($cart);
    }

    protected function loadData(CartService $cart): void
    {
        $cartModel = $cart->getCart();

        $items = $cartModel->items()
            ->with('product')
            ->get();

        $ndsRate = (int) config('settings.product.stavka_nds', 0);

        $this->rows = $items->map(function (CartItem $item) use ($ndsRate): array {
            $product = $item->product;
            $quantity = (int) $item->quantity;

            $price = (float) ($product?->price_int ?? 0);
            $discountPrice = (float) ($product?->discount ?? 0);
            $hasDiscount = (bool) ($product?->has_discount ?? false);

            $subtotal = $price * $quantity;
            $lineTotal = $hasDiscount ? ($discountPrice * $quantity) : $subtotal;

            $withDns = (bool) ($product?->with_dns ?? false)
                ? "НДС {$ndsRate}% в том числе"
                : "+ НДС {$ndsRate}%";

            return [
                'cart_item_id' => (int) $item->id,
                'id' => (int) ($product?->id ?? 0),
                'name' => $product?->name ?? 'Товар удалён',
                'slug' => $product?->slug,
                'image' => $product?->image,
                'price' => $price,
                'has_discount' => $hasDiscount,
                'with_dns' => $withDns,
                'qty' => $quantity,
                'subtotal' => $subtotal,
                'line_total' => $lineTotal,
                'url' => $product?->slug ? route('product.show', $product->slug) : null,
            ];
        });

        $this->totalQty = (int) $items->sum('quantity');
        $this->totalSum = (float) round($this->rows->sum('line_total'), 2);
        $this->discTotalSum = (float) round($this->rows->sum('subtotal'), 2);
        $this->discountSum = (float) round(
            $this->rows->sum(fn (array $row): float => max(0, $row['subtotal'] - $row['line_total'])),
            2
        );
    }

    protected function afterQtyChange(CartService $cart): void
    {
        $cart->getCart()->unsetRelation('items');
        $this->loadData($cart);

        $this->dispatch(
            'cart:updated',
            count: $cart->uniqueProductsCount(),
            totalQty: $this->totalQty,
            totalSum: $this->totalSum,
        );
    }

    public function render(): View
    {
        return view('livewire.pages.cart.index')
            ->layout('layouts.catalog', ['title' => 'Корзина']);
    }
}
