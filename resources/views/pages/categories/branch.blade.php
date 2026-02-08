<div class="mx-auto max-w-7xl px-4 py-10">
    <h1 class="text-3xl font-semibold">
        {{ $category?->name ?? 'Каталог' }}
    </h1>

    @if (!empty($category?->meta_description))
        <p class="mt-2 text-sm text-zinc-600">
            {{ $category->meta_description }}
        </p>
    @endif

    <div class="mt-8">
        <h2 class="text-lg font-semibold">Подкатегории</h2>

        <ul class="mt-3 grid gap-2 text-sm">
            @forelse ($subcategories as $sub)
                <li wire:key="subcategory-{{ $sub->id }}">
                    <a href="{{ route('catalog.leaf', ['path' => $sub->slug_path]) }}" class="hover:underline">
                        {{ $sub->name }}
                    </a>
                </li>
            @empty
                <li class="text-zinc-600">Подкатегорий пока нет.</li>
            @endforelse
        </ul>
    </div>
</div>
