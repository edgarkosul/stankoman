<div
    id="cart-modal"
    x-data="cartModal"
    x-on:cart:added.window="open($event.detail.product ?? null)"
    x-on:keydown.escape.window="close()"
    wire:ignore
>
    <div
        x-show="openState"
        x-transition.opacity.duration.200ms
        class="fixed inset-0 z-[70] bg-black/50"
        x-on:click="close()"
        aria-hidden="true"
        style="display: none;"
    ></div>

    <div
        x-show="openState"
        x-transition.scale.origin.top.duration.200ms
        class="fixed inset-0 z-[80] grid place-items-center p-4"
        x-on:click.self="close()"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cart-modal-title"
        style="display: none;"
    >
        <div class="relative w-full max-w-xl bg-white p-6 shadow-2xl">
            <button
                type="button"
                class="absolute right-3 top-3 p-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700"
                x-on:click="close()"
                aria-label="Закрыть"
            >
                <x-icon name="x" class="size-5 [&_.icon-base]:text-current [&_.icon-accent]:text-current" />
            </button>

            <template x-if="product">
                <div class="flex flex-col gap-4">
                    <h2 id="cart-modal-title" class="text-xl font-semibold text-zinc-900">Товар добавлен в корзину</h2>

                    <a :href="product.url" class="group  p-3 transition hover:border-zinc-300">
                        <div class="flex gap-4">
                            <div class="shrink-0">
                                <picture x-show="product.image">
                                    <source x-show="product.webp_srcset" type="image/webp" :srcset="product.webp_srcset" sizes="96px">
                                    <img
                                        :src="product.image"
                                        :alt="product.name"
                                        class="size-24  object-contain bg-white"
                                        loading="lazy"
                                        decoding="async"
                                        sizes="96px"
                                    >
                                </picture>
                                <div
                                    x-show="!product.image"
                                    class="flex size-24 items-center justify-center rounded-lg border border-dashed border-zinc-300 bg-zinc-50 text-xs text-zinc-500"
                                >
                                    Нет изображения
                                </div>
                            </div>

                            <div class="min-w-0">
                                <div class="line-clamp-2 text-sm font-medium text-zinc-900" x-text="product.name"></div>

                                <div class="mt-2 flex flex-col gap-1">
                                    <span
                                        x-show="product.has_discount"
                                        class="text-sm text-zinc-400 line-through"
                                        x-text="product.price_formatted"
                                    ></span>

                                    <span
                                        class="text-xl font-semibold text-zinc-900"
                                        x-text="product.has_discount ? product.price_final_formatted : product.price_formatted"
                                    ></span>
                                </div>
                            </div>
                        </div>
                    </a>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <a
                            href="{{ route('cart.index') }}"
                            class="inline-flex items-center justify-center  bg-brand-green px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-green/90"
                        >
                            Перейти в корзину
                        </a>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center  border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-50"
                            x-on:click="close()"
                        >
                            Продолжить покупки
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
