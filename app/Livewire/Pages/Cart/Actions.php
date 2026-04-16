<?php

namespace App\Livewire\Pages\Cart;

use App\Models\Product;
use App\Support\CartService;
use App\Support\Products\ProductEcommerceDataBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
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

    public function mount(): void
    {
        if ($this->variant !== 'leaf') {
            $this->extended = true;
        }

        $this->qty = $this->normalizedQty();

        $product = Product::query()
            ->select(['id', 'in_stock', 'price_amount'])
            ->find($this->productId);

        $this->isInStock = (bool) ($product?->in_stock ?? false);
        $this->isPrice = (bool) ($product?->price_amount ?? 0) > 0;

        if (! $this->supportsPersistentCart()) {
            $this->setTooltip();

            return;
        }

        $this->syncCartState(app(CartService::class));
    }

    public function add(CartService $cart, ProductEcommerceDataBuilder $ecommerceDataBuilder): void
    {
        if (! $this->supportsPersistentCart()) {
            return;
        }

        if (! $this->isInStock || ! $this->isPrice) {
            return;
        }

        $this->qty = $this->normalizedQty();
        $wasAlreadyInCart = $cart->isInCart($this->productId, $this->options);
        $cart->addItem($this->productId, $this->qty, $this->options);

        $this->inCart = true;
        $this->setTooltip();

        $this->dispatchCartUpdated($cart);
        $this->dispatch(
            'cart:added',
            productId: $this->productId,
            product: $this->modalProductPayload(),
        );

        if ($wasAlreadyInCart) {
            return;
        }

        $product = Product::query()
            ->with('categories.parent')
            ->find($this->productId);

        if (! $product instanceof Product) {
            return;
        }

        $this->dispatch(
            'ecommerce:add-to-cart',
            payload: $ecommerceDataBuilder->addToCartPayload($product, $this->qty),
        );
    }

    public function openOneClickOrder(): void
    {
        if (! $this->isInStock || ! $this->isPrice) {
            return;
        }

        $this->dispatch(
            'one-click-order:open',
            productId: $this->productId,
            quantity: $this->normalizedQty(),
        );
    }

    public function remove(CartService $cart): void
    {
        if (! $this->supportsPersistentCart()) {
            return;
        }

        $cart->removeItem($this->productId, $this->options);

        $this->inCart = false;
        $this->setTooltip();

        $this->dispatchCartUpdated($cart);
    }

    public function incrementQty(CartService $cart): void
    {
        $this->qty = $this->normalizedQty() + 1;

        if (! $this->supportsPersistentCart()) {
            return;
        }

        $this->syncQtyIfInCart($cart);
    }

    public function decrementQty(CartService $cart): void
    {
        $this->qty = max(1, $this->normalizedQty() - 1);

        if (! $this->supportsPersistentCart()) {
            return;
        }

        $this->syncQtyIfInCart($cart);
    }

    public function setQty(CartService $cart): void
    {
        if (! $this->supportsPersistentCart()) {
            $this->qty = $this->normalizedQty();
            $this->setTooltip();

            return;
        }

        $quantity = $this->normalizedQty();
        $this->qty = $quantity;

        if (! $cart->isInCart($this->productId, $this->options)) {
            $this->setTooltip();

            return;
        }

        $cart->updateQuantity($this->productId, $quantity, $this->options);

        $this->inCart = true;
        $this->setTooltip();

        $this->dispatchCartUpdated($cart);
    }

    public function updatedQty(): void
    {
        $this->qty = $this->normalizedQty();
    }

    #[On('cart:updated.{productId}')]
    public function sync(CartService $cart): void
    {
        if (! $this->supportsPersistentCart()) {
            $this->inCart = false;
            $this->qty = $this->normalizedQty();
            $this->setTooltip();

            return;
        }

        $this->syncCartState($cart);
    }

    protected function dispatchCartUpdated(CartService $cart): void
    {
        $count = $this->count($cart);

        $this->dispatch("cart:updated.{$this->productId}", count: $count);
        $this->dispatch('cart:updated', count: $count);
    }

    protected function count(CartService $cart): int
    {
        return $cart->uniqueProductsCount();
    }

    protected function syncCartState(CartService $cart): void
    {
        $this->inCart = $cart->isInCart($this->productId, options: null, strictOptions: false);

        if ($this->inCart) {
            $this->qty = max(
                1,
                $cart->quantityFor($this->productId, $this->options) ?: $this->normalizedQty(),
            );
        } else {
            $this->qty = $this->normalizedQty();
        }

        $this->setTooltip();
    }

    protected function supportsPersistentCart(): bool
    {
        return Schema::hasTable('carts') && Schema::hasTable('cart_items');
    }

    protected function syncQtyIfInCart(CartService $cart): void
    {
        if (! $this->inCart) {
            return;
        }

        $this->setQty($cart);
    }

    protected function normalizedQty(): int
    {
        return max(1, (int) $this->qty);
    }

    /**
     * @return array{id:int,name:string,url:string,image:?string,webp_srcset:?string,price_formatted:string,price_final_formatted:string,has_discount:bool}
     */
    protected function modalProductPayload(): array
    {
        $product = Product::query()
            ->select(['id', 'name', 'slug', 'image', 'price_amount', 'discount_price'])
            ->find($this->productId);

        if (! $product instanceof Product) {
            return [
                'id' => $this->productId,
                'name' => 'Товар',
                'url' => route('cart.index', [], false),
                'image' => null,
                'webp_srcset' => null,
                'price_formatted' => price(0),
                'price_final_formatted' => price(0),
                'has_discount' => false,
            ];
        }

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'url' => route('product.show', ['product' => $product->slug], false),
            'image' => $product->image_url,
            'webp_srcset' => $product->image_webp_srcset,
            'price_formatted' => price($product->price_int),
            'price_final_formatted' => price($product->price_final),
            'has_discount' => (bool) $product->has_discount,
        ];
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
