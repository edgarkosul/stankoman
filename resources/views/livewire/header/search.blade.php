<form
    wire:submit.prevent="goFull"
    role="search"
    aria-label="Поиск по каталогу товаров"
    class="relative {{ $class }}"
    x-data="{
        active: $wire.entangle('active').live,
        open: $wire.entangle('open').live,
        items() { return $el.querySelectorAll('[data-suggest]') },
        count() { return this.items().length },
        slug(i) { return this.items()[i]?.dataset.slug ?? null },
        move(direction) {
            const total = this.count();

            if (! total) {
                return;
            }

            this.active = ((this.active ?? -1) + direction + total) % total;
            this.open = true;
            this.items()[this.active]?.scrollIntoView({ block: 'nearest' });
        },
        choose(i) {
            const slug = this.slug(i);

            if (slug) {
                $wire.goTo(slug);

                return;
            }

            $wire.goFull();
        },
        actId(i) { return `hs-opt-${i}` },
    }"
    @click.outside="open = false"
>
    <label for="header-search-q" class="sr-only">Поиск по каталогу товаров</label>

    <input
        id="header-search-q"
        name="q"
        type="search"
        autocomplete="off"
        placeholder="Поиск по каталогу товаров"
        class="h-11 w-full border-2 border-brand-green pl-3 pr-10 outline-none focus:ring-2 focus:ring-brand-green"
        wire:model.live.debounce.300ms="q"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-activedescendant="active >= 0 ? actId(active) : null"
        @focus="open = count() > 0"
        @keydown.arrow-down.prevent.stop="move(1)"
        @keydown.arrow-up.prevent.stop="move(-1)"
        @keydown.escape.prevent.stop="open = false"
        @keydown.enter.prevent="(active ?? -1) >= 0 ? choose(active) : $wire.goFull()"
    />

    <button
        type="submit"
        class="absolute inset-y-0 right-0 grid w-14 cursor-pointer place-items-center rounded-r-md bg-brand-green hover:bg-[#1c7731] focus:outline-none focus:ring-2 focus:ring-brand-green"
        aria-label="Найти"
        title="Найти"
    >
        <x-icon name="lupa" class="h-5 w-5 text-white" />
    </button>

    @if ($this->results->isNotEmpty())
        <div
            x-show="open"
            x-transition.opacity.duration.150ms
            x-cloak
            class="absolute left-0 right-0 z-50 mt-2 overflow-hidden border bg-white shadow"
        >
            <ul class="max-h-96 divide-y overflow-auto" role="listbox">
                @foreach ($this->results as $i => $product)
                    <li
                        data-suggest
                        data-slug="{{ $product->slug }}"
                        role="option"
                        :id="actId({{ $i }})"
                        :aria-selected="{{ $i }} === active"
                    >
                        <button
                            type="button"
                            wire:key="header-suggest-{{ $product->id }}"
                            class="flex w-full items-center justify-between px-3 py-2 text-left hover:bg-zinc-50"
                            :class="{ 'bg-zinc-100': {{ $i }} === active }"
                            @mouseenter="active = {{ $i }}"
                            @click="choose({{ $i }})"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {!! $this->highlight($product->name) !!}
                                </div>

                                @if ($product->sku)
                                    <div class="text-xs text-zinc-500">
                                        Артикул: {!! $this->highlight($product->sku) !!}
                                    </div>
                                @endif
                            </div>

                            <div class="whitespace-nowrap pl-3 text-sm font-semibold">
                                @if ($product->discount_price > 0)
                                    @if ($product->price > 0)
                                        <span class="mr-2 text-zinc-400 line-through">
                                            {{ price($product->price) }}
                                        </span>
                                    @endif

                                    <span>{{ price($product->discount_price) }}</span>
                                @elseif ($product->price > 0)
                                    <span>{{ price($product->price) }}</span>
                                @endif
                            </div>
                        </button>
                    </li>
                @endforeach

                <li>
                    <button
                        type="button"
                        class="w-full px-3 py-2 text-left text-sm text-zinc-600 hover:bg-zinc-50"
                        wire:click="goFull"
                    >
                        Показать все результаты для «{{ $q }}»
                    </button>
                </li>
            </ul>
        </div>
    @endif
</form>
