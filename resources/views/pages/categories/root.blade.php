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
                    $visibleChildren = $sub->children->take(5);
                    $hiddenChildren = $sub->children->slice(5);
                @endphp

                <article
                    wire:key="subcategory-{{ $sub->id }}"
                    class="relative isolate rounded-none bg-brand-gray/20 shadow-sm transition-shadow hover:shadow-lg overflow-hidden"
                >
                    @if ($sub->image_url)
                        <img
                            src="{{ $sub->image_url }}"
                            alt="{{ $sub->name }}"
                            class="pointer-events-none absolute -right-8 -bottom-5 h-40 w-40 object-contain mix-blend-multiply"
                            loading="lazy"
                        >
                    @endif

                    <div class="p-4 pb-30">
                        <a
                            href="{{ route('catalog.leaf', ['path' => $sub->slug_path]) }}"
                            class="text-lg font-semibold text-zinc-900 hover:underline drop-shadow-[0_0_4px_rgba(255,255,255,1)]"
                        >
                            {{ $sub->name }}
                        </a>

                        @if ($sub->children->isNotEmpty())
                            <ul class="mt-3 grid gap-1 text-sm text-zinc-900">
                                @foreach ($visibleChildren as $child)
                                    <li>
                                        <a
                                            href="{{ route('catalog.leaf', ['path' => $sub->slug_path . '/' . $child->slug]) }}"
                                            class="hover:underline drop-shadow-[0_0_2px_rgba(255,255,255,1)]"
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
            @empty
                <div class="text-zinc-600">Подкатегорий пока нет.</div>
            @endforelse
        </div>
    </div>
</div>
