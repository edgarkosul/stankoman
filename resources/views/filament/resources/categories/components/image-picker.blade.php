@php
    use App\Models\Category;

    $selectedPath = Category::normalizeImagePath($get('img'));
    $previewUrl = Category::resolveImageUrl($selectedPath);
@endphp

<div class="space-y-4">
    <div class="space-y-2">
        <div class="text-sm font-medium text-gray-950 dark:text-white">Изображение категории</div>

        @if ($record)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Выберите картинку из поля <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-white/10">products.image</code>.
                <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-white/10">thumb</code> и
                <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-white/10">gallery</code> не используются.
            </p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Сохраните категорию, затем выберите изображение из товаров.
            </p>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1 space-y-3">
                @if ($previewUrl)
                    <div class="flex items-start gap-4">
                        <div class="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                            <img
                                src="{{ $previewUrl }}"
                                alt="Текущее изображение категории"
                                class="max-h-full max-w-full object-contain"
                                loading="lazy"
                            >
                        </div>

                        <div class="min-w-0 space-y-2">
                            <div class="text-sm font-medium text-gray-950 dark:text-white">Текущее изображение</div>
                            <div class="break-all text-xs text-gray-500 dark:text-gray-400">{{ $selectedPath }}</div>
                        </div>
                    </div>
                @else
                    <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-sm text-gray-500 dark:border-white/15 dark:bg-white/5 dark:text-gray-400">
                        Изображение пока не выбрано.
                    </div>
                @endif
            </div>

            @if ($record)
                <div class="flex shrink-0 flex-wrap gap-2">
                    <x-filament::button
                        type="button"
                        color="gray"
                        icon="heroicon-o-photo"
                        wire:click="openCategoryImagePicker"
                    >
                        Выбрать из товаров
                    </x-filament::button>

                    @if ($selectedPath)
                        <x-filament::button
                            type="button"
                            color="danger"
                            wire:click="clearCategoryImage"
                        >
                            Очистить
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
