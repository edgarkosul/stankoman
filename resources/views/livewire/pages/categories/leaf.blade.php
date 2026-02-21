<section class="mx-auto max-w-7xl px-4 py-6 space-y-4 bg-zinc-100/80">
    <h1 class="text-3xl font-bold">{{ $category->name }}</h1>

    <div class="flex flex-col md:flex-row gap-4">
        <aside x-data="{
            filtersOpen: false,
            isMd: window.matchMedia('(min-width:768px)').matches,
        }" x-init="const mql = window.matchMedia('(min-width:768px)');
        const sync = () => isMd = mql.matches;
        (mql.addEventListener ? mql.addEventListener('change', sync) : mql.addListener(sync));
        sync();

        const openFilters = () => filtersOpen = true;
        const closeFilters = () => filtersOpen = false;
        const toggleFilters = () => filtersOpen = !filtersOpen;
        window.addEventListener('filters:open', openFilters);
        window.addEventListener('filters:close', closeFilters);
        window.addEventListener('filters:toggle', toggleFilters);

        this.__scrollY = 0;
        this.__lockScroll = () => {
            if (this.isMd) return;
            this.__scrollY = window.scrollY || document.documentElement.scrollTop;
            document.body.style.position = 'fixed';
            document.body.style.top = `-${this.__scrollY}px`;
            document.body.style.left = '0';
            document.body.style.right = '0';
            document.body.style.width = '100%';
            document.documentElement.style.overflow = 'hidden';
        };
        this.__unlockScroll = () => {
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.left = '';
            document.body.style.right = '';
            document.body.style.width = '';
            document.documentElement.style.overflow = '';
            window.scrollTo(0, this.__scrollY);
        };"
            x-effect="(filtersOpen && !isMd) ? this.__lockScroll() : this.__unlockScroll()"
            @keydown.escape.window="filtersOpen = false" class="contents md:block md:w-80 shrink-0" x-cloak>
            {{-- Оверлей для модалки --}}
            <div x-show="filtersOpen && !isMd" class="fixed inset-0 z-40 bg-black/40 md:hidden"
                @click="filtersOpen = false" x-transition.opacity></div>
            {{-- Модалка с фильтрами --}}
            <div x-show="isMd || filtersOpen" x-transition:enter="transition-transform duration-200"
                x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform duration-200" x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                x-effect="if (filtersOpen && !isMd) $nextTick(() => $el.focus())" role="dialog" aria-modal="true"
                tabindex="-1"
                class="fixed right-0 top-0 z-50 bg-white h-dvh w-[min(90vw,24rem)] box-border
                    p-4  overflow-y-auto overscroll-contain
                    md:sticky md:top-24 md:self-start md:z-auto md:w-auto md:h-auto md:shadow-none md:translate-x-0
                    md:max-h-[calc(100dvh-6rem)]">
                <div class="md:hidden flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold">Фильтры</h2>
                    <button type="button" @click="filtersOpen = false" aria-label="Закрыть">x</button>
                </div>

                <div id="filters-panel" class="space-y-4">
                    @foreach (collect($schema)->sortBy('order') as $f)
                        <div class="flex flex-col items-start gap-2 flex-wrap mb-5">
                            @switch($f['type'])
                                @case('range')
                                    <div class="font-semibold whitespace-nowrap text-md mb-1">
                                        {{ $f['label'] }}@if (!empty($f['meta']['suffix']))
                                            ,
                                        @endif
                                        @if (!empty($f['meta']['suffix']))
                                            <span class="font-normal">{{ $f['meta']['suffix'] }}</span>
                                        @endif
                                    </div>

                                    <div class="w-full" wire:key="filter-range-{{ $f['key'] }}" data-range="slider"
                                        data-key="{{ $f['key'] }}" data-min="{{ $f['meta']['min'] }}"
                                        data-max="{{ $f['meta']['max'] }}" data-step="{{ $f['meta']['step'] ?? 1 }}">
                                        <div class="px-2 pl-2 pr-5" wire:ignore>
                                            <div class="js-range-slider ks-range" wire:ignore></div>
                                        </div>

                                        <div class="mt-4 flex items-center gap-4 justify-between">
                                            @php
                                                $step = $f['meta']['step'] ?? 1;
                                                $hasFractionalStep = is_numeric($step)
                                                    ? fmod((float) $step, 1.0) !== 0.0
                                                    : is_string($step) &&
                                                        (strpos((string) $step, '.') !== false ||
                                                            strpos((string) $step, ',') !== false);
                                            @endphp

                                            @php
                                                $currMin = data_get($this->filters, "{$f['key']}.min");
                                                $formattedMin =
                                                    $currMin !== null && $currMin !== ''
                                                        ? number_format(
                                                            (float) $currMin,
                                                            $f['meta']['decimals'] ?? 0,
                                                            ',',
                                                            ' ',
                                                        )
                                                        : '';
                                            @endphp

                                            @if ($hasFractionalStep)
                                                <div class="flex-1 min-w-20">
                                                    <input type="number"
                                                        class="js-range-min w-full border border-brand-green bg-white px-2 py-1 text-sm no-spin focus:ring-2 focus:ring-brand-green"
                                                        min="{{ $f['meta']['min'] }}" max="{{ $f['meta']['max'] }}"
                                                        step="{{ $f['meta']['step'] }}" inputmode="decimal" autocomplete="off"
                                                        placeholder="{{ number_format($f['meta']['min'], $f['meta']['decimals'] ?? 0, ',', ' ') }}"
                                                        value="{{ $currMin }}">
                                                </div>
                                            @else
                                                <div x-data="prettyNumberInput({ decimals: {{ $f['meta']['decimals'] ?? 0 }} })" class="flex-1 min-w-20">
                                                    <input x-ref="hidden" type="number" class="js-range-min hidden"
                                                        min="{{ $f['meta']['min'] }}" max="{{ $f['meta']['max'] }}"
                                                        step="{{ $f['meta']['step'] }}" value="{{ $currMin }}">

                                                    <input x-ref="visible" wire:ignore type="text" @input="onInput($event)"
                                                        class="w-full border border-brand-green bg-white px-2 py-1 text-sm no-spin focus:ring-2 focus:ring-brand-green"
                                                        inputmode="decimal" autocomplete="off"
                                                        placeholder="{{ number_format($f['meta']['min'], 0, ',', ' ') }}"
                                                        value="{{ $formattedMin }}">
                                                </div>
                                            @endif

                                            @php
                                                $currMax = data_get($this->filters, "{$f['key']}.max");
                                                $formattedMax =
                                                    $currMax !== null && $currMax !== ''
                                                        ? number_format(
                                                            (float) $currMax,
                                                            $f['meta']['decimals'] ?? 0,
                                                            ',',
                                                            ' ',
                                                        )
                                                        : '';
                                            @endphp

                                            @if ($hasFractionalStep)
                                                <div class="flex-1 min-w-20">
                                                    <input type="number"
                                                        class="js-range-max w-full border border-brand-green bg-white px-2 py-1 text-sm no-spin focus:ring-2 focus:ring-brand-green"
                                                        min="{{ $f['meta']['min'] }}" max="{{ $f['meta']['max'] }}"
                                                        step="{{ $f['meta']['step'] }}" inputmode="decimal" autocomplete="off"
                                                        placeholder="{{ number_format($f['meta']['max'], $f['meta']['decimals'] ?? 0, ',', ' ') }}"
                                                        value="{{ $currMax }}">
                                                </div>
                                            @else
                                                <div x-data="prettyNumberInput({ decimals: {{ $f['meta']['decimals'] ?? 0 }} })" class="flex-1 min-w-20">
                                                    <input x-ref="hidden" type="number" class="js-range-max hidden"
                                                        min="{{ $f['meta']['min'] }}" max="{{ $f['meta']['max'] }}"
                                                        step="{{ $f['meta']['step'] }}" value="{{ $currMax }}">

                                                    <input x-ref="visible" wire:ignore type="text" @input="onInput($event)"
                                                        class="w-full border border-brand-green bg-white px-2 py-1 text-sm no-spin focus:ring-2 focus:ring-brand-green"
                                                        inputmode="decimal" autocomplete="off"
                                                        placeholder="{{ number_format($f['meta']['max'], 0, ',', ' ') }}"
                                                        value="{{ $formattedMax }}">
                                                </div>
                                            @endif
                                        </div>

                                        <button type="button" class="mt-2 text-xs text-zinc-500 underline js-range-reset"
                                            wire:click="clearFilter('{{ $f['key'] }}')">
                                            Сбросить
                                        </button>
                                    </div>
                                @break

                                @case('boolean')
                                    <div class="font-semibold whitespace-nowrap text-md mb-1">
                                        {{ $f['label'] }}@if (!empty($f['meta']['suffix']))
                                            ,
                                        @endif
                                        @if (!empty($f['meta']['suffix']))
                                            <span class="font-normal">{{ $f['meta']['suffix'] }}</span>
                                        @endif
                                    </div>

                                    <div class="flex flex-col items-start">
                                        @php
                                            $key = $f['key'];
                                            $isOn = (bool) data_get($this->filters, "{$key}.value", false);
                                        @endphp

                                        <label class="inline-flex items-center gap-3 cursor-pointer select-none">
                                            <input type="checkbox" class="sr-only peer"
                                                wire:model.live="filters.{{ $key }}.value">
                                            <span
                                                class="relative inline-block h-7 w-13 rounded-full bg-zinc-300 transition-colors duration-200 ease-out
                                                peer-checked:bg-brand-green peer-focus-visible:ring-2 peer-focus-visible:ring-brand-green/50
                                                peer-disabled:opacity-50 peer-disabled:cursor-not-allowed
                                                after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:h-6 after:w-6 after:rounded-full after:bg-white after:shadow
                                                after:transition-all after:duration-200 after:ease-out after:will-change-left
                                                peer-checked:after:left-[1.6rem]
                                                peer-active:after:scale-95"></span>
                                        </label>

                                        <button type="button" wire:click="$set('filters.{{ $key }}.value', null)"
                                            @disabled(!$isOn)
                                            class="mt-2 text-xs underline transition
                                            {{ $isOn ? 'text-zinc-500 hover:text-zinc-700' : 'text-zinc-300 cursor-not-allowed' }}">
                                            Сбросить
                                        </button>
                                    </div>
                                @break

                                @case('select')
                                    <div class="font-semibold whitespace-nowrap text-md mb-1">
                                        {{ $f['label'] }}@if (!empty($f['meta']['suffix']))
                                            ,
                                        @endif
                                        @if (!empty($f['meta']['suffix']))
                                            <span class="font-normal">{{ $f['meta']['suffix'] }}</span>
                                        @endif
                                    </div>

                                    <select class="w-full rounded border border-zinc-300 bg-white px-3 py-2 text-sm"
                                        wire:model.live="filters.{{ $f['key'] }}.value">
                                        <option value="">—</option>
                                        @foreach ($f['meta']['options'] as $o)
                                            <option value="{{ $o['v'] }}">{{ $o['l'] }}</option>
                                        @endforeach
                                    </select>
                                @break

                                @case('multiselect')
                                    <div class="font-semibold whitespace-nowrap text-md mb-1">
                                        {{ $f['label'] }}
                                        @if (!empty($f['meta']['suffix']))
                                            <span class="font-normal">{{ $f['meta']['suffix'] }}</span>
                                        @endif
                                    </div>

                                    @php
                                        $key = $f['key'];
                                    @endphp
                                    <div class="flex flex-col items-start">
                                        <div class="flex flex-wrap gap-2 max-h-44 overflow-auto pr-1">
                                            @foreach ($f['meta']['options'] as $o)
                                                @php $id = "ms-{$key}-" . md5($o['v']); @endphp
                                                <label for="{{ $id }}" class="inline-flex">
                                                    <input id="{{ $id }}" type="checkbox" class="peer sr-only"
                                                        wire:key="ms-{{ $key }}-{{ $o['v'] }}"
                                                        value="{{ (string) $o['v'] }}"
                                                        name="filters[{{ $key }}][values][]"
                                                        wire:model.live="filters.{{ $key }}.values">
                                                    <span
                                                        class="select-none text-sm px-3 py-1 border
                                                            transition
                                                            border-zinc-300 bg-white
                                                            hover:border-zinc-400 hover:peer-checked:bg-brand-green/80
                                                            peer-checked:bg-brand-green peer-checked:text-white peer-checked:border-brand-green
                                                            peer-focus:border-brand-green/50">
                                                        {{ $o['l'] }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>

                                        @php
                                            $hasSelected = filled(data_get($this->filters, "{$key}.values", []));
                                        @endphp

                                        <button type="button" wire:click="$set('filters.{{ $key }}.values', [])"
                                            @disabled(!$hasSelected)
                                            class="mt-2 text-xs underline transition
                                            {{ $hasSelected ? 'text-zinc-500 hover:text-zinc-700 cursor-pointer' : 'text-zinc-300 cursor-not-allowed' }}">
                                            Сбросить
                                        </button>
                                    </div>
                                @break
                            @endswitch
                        </div>
                    @endforeach

                    <div class="flex items-center">
                        @if ($filters)
                            <button class="text-sm text-zinc-600" wire:click="clearAll">Сбросить все</button>
                        @endif
                        <button type="button" class="ml-auto text-sm text-zinc-600"
                            @click="document.activeElement && document.activeElement.blur()">
                            Применить
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        <div class="flex-1 space-y-4">
            <div class="flex flex-wrap gap-2 items-center">
                <div class="md:hidden mb-3">
                    <button type="button" class="inline-flex items-center gap-2 rounded border px-3 py-2 bg-white"
                        @click="window.dispatchEvent(new CustomEvent('filters:open'))">
                        <span>Фильтры</span>
                    </button>
                </div>

                <input type="search" wire:model.live.debounce.500ms="q" placeholder="Поиск в разделе..."
                    class="max-w-full w-64 rounded border border-zinc-300 pl-3 pr-10 h-10 outline-none focus:ring-2 focus:ring-brand-green bg-white" />

                <select wire:model.live="sort" class="h-10 border border-zinc-300 bg-white px-3">
                    <option value="popular">По популярности</option>
                    <option value="price_asc">Цена по возрастанию</option>
                    <option value="price_desc">Цена по убыванию</option>
                    <option value="new">Новинки</option>
                </select>
            </div>

            @if (count($this->activeFilters))
                <div class="flex flex-wrap gap-2 items-center">
                    <button type="button" wire:click="clearAll"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm text-brand-green hover:bg-zinc-50">
                        Сбросить все
                    </button>

                    @foreach ($this->activeFilters as $f)
                        <span
                            class="inline-flex items-center gap-2 rounded-full border border-brand-green/40 bg-brand-green/10 pl-3 pr-2 py-1 text-sm">
                            <span class="text-brand-green">
                                <span class="font-medium">{{ $f['label'] }}:</span>
                                {{ $f['display'] }}
                            </span>

                            @if (($f['action'] ?? 'all') === 'value')
                                <button type="button"
                                    wire:click="removeFilterValue('{{ $f['key'] }}', '{{ $f['raw'] }}')"
                                    class="shrink-0 text-brand-green hover:opacity-70"
                                    aria-label="Убрать значение">x</button>
                            @else
                                <button type="button" wire:click="clearFilter('{{ $f['key'] }}')"
                                    class="shrink-0 text-brand-green hover:opacity-70"
                                    aria-label="Снять фильтр">x</button>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif

            <div id="category-products-top"></div>

            @if ($products->isEmpty())
                <p class="text-zinc-600">Товары не найдены.</p>
            @else
                <div class="relative">
                    <div class="grid grid-cols-2 xl:grid-cols-3 gap-4">
                        @foreach ($products as $product)
                            <x-product.card :product="$product" :category="$category" />
                        @endforeach
                    </div>

                    @if ($this->hasMoreProducts)
                        <div wire:intersect="loadMore"
                            class="absolute inset-x-0 bottom-40 h-1 opacity-0 pointer-events-none" aria-hidden="true">
                        </div>

                        <div wire:loading wire:target="loadMore" class="pt-4 text-center text-zinc-500">
                            Загрузка...
                        </div>
                    @elseif (!$this->hasMoreProducts && $products->isNotEmpty())
                        <div class="pt-4 text-center text-zinc-500">
                            По вашему запросу больше товаров нет.
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</section>
