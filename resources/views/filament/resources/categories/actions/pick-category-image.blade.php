<div class="space-y-6">
    <div class="space-y-2">
        <div class="text-sm text-gray-600">
            Доступно {{ $candidates->total() }} уникальных изображений
            из {{ $scopeLeafCategoryCount }} листовых {{ $scopeLeafCategoryCount === 1 ? 'категории' : 'категорий' }}.
        </div>

        <label class="block">
            <span class="sr-only">Поиск по названию или SKU</span>
            <input
                type="text"
                wire:model.live.debounce.300ms="categoryImageSearch"
                placeholder="Поиск по названию товара или SKU"
                class="block w-full rounded-xl border-0 bg-white px-3 py-2 text-sm text-gray-950 ring-1 ring-gray-300 shadow-sm outline-none transition focus:ring-2 focus:ring-primary-500"
            >
        </label>
    </div>

    @if ($candidates->total() > 0)
        <div class="flex items-center justify-between gap-3 text-sm text-gray-500">
            <div>
                Показано {{ $candidates->count() }} из {{ $candidates->total() }}
            </div>

            <div>
                Страница {{ $candidates->currentPage() }} из {{ max(1, $candidates->lastPage()) }}
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($candidates as $candidate)
                @php
                    $isSelected = $selectedPath === $candidate['path'];
                @endphp

                <button
                    type="button"
                    wire:key="category-image-candidate-{{ md5($candidate['path']) }}"
                    x-on:click="$wire.selectCategoryImage(@js($candidate['path']))"
                    class="{{ $isSelected
                        ? 'ring-primary-500 border-primary-500 bg-primary-50/70'
                        : 'border-gray-200 bg-white hover:border-gray-300' }} flex w-full flex-col gap-3 rounded-2xl border p-3 text-left transition"
                >
                    <div class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-gray-50 p-3">
                        <img
                            src="{{ $candidate['preview_url'] }}"
                            alt="{{ $candidate['product_name'] }}"
                            class="max-h-full max-w-full object-contain"
                            loading="lazy"
                        >
                    </div>

                    <div class="space-y-2">
                        <div class="line-clamp-2 text-sm font-medium text-gray-950">
                            {{ $candidate['product_name'] }}
                        </div>

                        @if ($candidate['product_sku'] !== '')
                            <div class="text-xs text-gray-500">
                                SKU: {{ $candidate['product_sku'] }}
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-2 text-xs">
                            <div class="truncate text-gray-500">
                                {{ $candidate['path'] }}
                            </div>

                            <span class="{{ $candidate['is_active'] ? 'text-success-600' : 'text-gray-400' }}">
                                {{ $candidate['is_active'] ? 'Активен' : 'Скрыт' }}
                            </span>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        @if ($candidates->lastPage() > 1)
            <div class="flex items-center justify-end gap-2">
                <x-filament::button
                    type="button"
                    color="gray"
                    outlined
                    wire:click="previousCategoryImagePage"
                    :disabled="$candidates->onFirstPage()"
                >
                    Назад
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    outlined
                    wire:click="nextCategoryImagePage"
                    :disabled="! $candidates->hasMorePages()"
                >
                    Вперёд
                </x-filament::button>
            </div>
        @endif
    @else
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-10 text-center text-sm text-gray-500">
            @if (filled($this->categoryImageSearch))
                Ничего не найдено. Попробуйте изменить запрос.
            @else
                В выбранной категории нет товаров с заполненным <code class="rounded bg-white px-1 py-0.5 text-xs">image</code>.
            @endif
        </div>
    @endif
</div>
