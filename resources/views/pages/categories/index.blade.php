@php
    $isRoot = $isRoot ?? empty($category);
    $cardBaseClasses = 'relative isolate rounded-none bg-brand-gray/20 shadow-sm transition-shadow hover:shadow-lg overflow-hidden';
    $cardClasses = $isRoot ? $cardBaseClasses : $cardBaseClasses . ' min-h-64';
    $cardLinkClasses = $cardClasses . ' block';
    $imageClasses = $isRoot
        ? 'pointer-events-none absolute -right-8 -bottom-5 h-40 w-40 object-contain mix-blend-multiply'
        : 'pointer-events-none absolute -right-5 -bottom-5 h-48 w-48 object-contain mix-blend-multiply';
    $contentClasses = $isRoot ? 'p-4 pb-30' : 'p-4 pr-24';
    $titleClasses = $isRoot
        ? 'text-lg font-semibold text-zinc-900 hover:underline drop-shadow-[0_0_4px_rgba(255,255,255,1)]'
        : 'text-base font-semibold text-zinc-900 hover:underline';
    $visibleChildLinkClasses = $isRoot
        ? 'hover:underline drop-shadow-[0_0_2px_rgba(255,255,255,1)]'
        : 'hover:underline';
@endphp

<div class="mx-auto max-w-7xl px-4 py-6">
    <h1 class="text-3xl font-semibold">
        {{ $category?->name ?? 'Каталог' }}
    </h1>

    @if (!empty($category?->meta_description))
        <p class="mt-2 text-sm text-zinc-600">
            {{ $category->meta_description }}
        </p>
    @endif

    <div class="mt-8">
        <div class="mt-3 grid gap-4 text-sm xs:grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($subcategories as $sub)
                @php
                    $hasChildren = $sub->children->isNotEmpty();
                    $visibleChildren = $sub->children->take(5);
                    $hiddenChildren = $sub->children->slice(5);
                @endphp

                @if (! $isRoot && ! $hasChildren)
                    <a
                        wire:key="subcategory-{{ $sub->id }}"
                        href="{{ route('catalog.leaf', ['path' => $sub->slug_path]) }}"
                        class="{{ $cardLinkClasses }}"
                    >
                        @if ($sub->image_url)
                            <img
                                src="{{ $sub->image_url }}"
                                alt="{{ $sub->name }}"
                                class="{{ $imageClasses }}"
                                loading="lazy"
                            >
                        @endif

                        <div class="{{ $contentClasses }}">
                            <span class="text-base font-semibold text-zinc-900">
                                {{ $sub->name }}
                            </span>

                            @php($productsCount = (int) ($sub->products_count ?? 0))
                            <p class="mt-3 text-sm text-zinc-600">
                                {{ $productsCount }} {{ $this->productPlural($productsCount) }}
                            </p>
                        </div>
                    </a>
                @else
                    <article
                        wire:key="subcategory-{{ $sub->id }}"
                        class="{{ $cardClasses }}"
                    >
                        @if ($sub->image_url)
                            <img
                                src="{{ $sub->image_url }}"
                                alt="{{ $sub->name }}"
                                class="{{ $imageClasses }}"
                                loading="lazy"
                            >
                        @endif

                        <div class="{{ $contentClasses }}">
                            <a
                                href="{{ route('catalog.leaf', ['path' => $sub->slug_path]) }}"
                                class="{{ $titleClasses }}"
                            >
                                {{ $sub->name }}
                            </a>

                            @if ($hasChildren)
                                <ul class="mt-3 grid gap-1 text-sm text-zinc-900">
                                    @foreach ($visibleChildren as $child)
                                        <li>
                                            <a
                                                href="{{ route('catalog.leaf', ['path' => $sub->slug_path . '/' . $child->slug]) }}"
                                                class="{{ $visibleChildLinkClasses }}"
                                            >
                                                {{ $child->name }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($hiddenChildren->isNotEmpty())
                                    @php($hiddenCount = $hiddenChildren->count())
                                    <details class="group mt-3">
                                        <summary class="flex cursor-pointer items-center gap-2 text-sm font-medium text-brand-red drop-shadow-[0_0_2px_rgba(255,255,255,1)]">
                                            <span>Еще {{ $hiddenCount }} {{ $this->categoryPlural($hiddenCount) }}</span>
                                            <svg
                                                class="h-4 w-4 transition-transform group-open:rotate-180"
                                                viewBox="0 0 16 16"
                                                aria-hidden="true"
                                                fill="currentColor"
                                            >
                                                <path d="M4.22 5.97a.75.75 0 0 1 1.06 0L8 8.69l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.03a.75.75 0 0 1 0-1.06Z"/>
                                            </svg>
                                        </summary>

                                        <div class="grid max-h-0 gap-1 overflow-hidden text-sm text-zinc-900 transition-[max-height] duration-300 group-open:max-h-96 pt-2">
                                            @foreach ($hiddenChildren as $child)
                                                <a
                                                    href="{{ route('catalog.leaf', ['path' => $sub->slug_path . '/' . $child->slug]) }}"
                                                    class="hover:underline"
                                                >
                                                    {{ $child->name }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            @endif
                        </div>
                    </article>
                @endif
            @empty
                <div class="text-zinc-600">Подкатегорий пока нет.</div>
            @endforelse
        </div>
    </div>
</div>
