<div class="flex gap-4 sm:gap-6">
    @php
        $currentUser = auth()->user();
        $filamentAdminPanel = \Filament\Facades\Filament::getPanel('admin', isStrict: false);
        $canAccessFilamentAdmin =
            $currentUser !== null && $filamentAdminPanel !== null && $currentUser->canAccessPanel($filamentAdminPanel);
    @endphp

    <div class="group z-30 flex items-center gap-2">
        <livewire:pages.product.favorite-toggle :product-id="$product->id" :variant="'compare'" :key="'favorite-show-' . $product->id" />
    </div>
    <div class="group z-30 flex items-center gap-2">
        <livewire:pages.product.compare-toggle :product-id="$product->id" :variant="'compare'" :key="'compare-show-' . $product->id" />
    </div>
    <div class="group z-30 cursor-pointer flex items-center gap-2">
        <a x-data x-tooltip.top.offset-10.skid-30="'Печать'" href="{{ route('product.print', $product) }}" target="_blank"
            class="flex items-center gap-1">
            <x-icon name="print"
                class="size-6 text-zinc-700 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
            <span class="whitespace-nowrap text-zinc-800 hover:text-brand-red hidden md:block">Печать</span>
        </a>
    </div>
    <div class="group z-30 cursor-pointer flex items-center gap-2">
        <a x-data x-tooltip.top.offset-10.skid-30="'Скачать PDF'"
            href="{{ route('product.print', ['product' => $product, 'dl' => 1]) }}" class="flex items-center gap-1">
            <x-icon name="pdf"
                class="size-6 text-zinc-700 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
            <span class="whitespace-nowrap text-zinc-800 hover:text-brand-red hidden md:block">Скачать PDF</span>
        </a>
    </div>
    <div class="group z-30 cursor-pointer flex items-center gap-2">
        <button x-data x-tooltip.top.offset-10.skid-30="'Поделиться'" type="button"
            class="js-share-product flex items-center gap-1"
            aria-label="Поделиться"
            data-title="{{ $product->name }}"
            data-text="{{ $product->name }}"
            data-url="{{ route('product.show', $product) }}">
            <x-heroicon-o-share class="size-6 text-zinc-700 group-hover:text-rose-600" />
            <span
                class="js-share-product-label whitespace-nowrap text-zinc-800 group-hover:text-brand-red hidden md:block">Поделиться</span>
        </button>
    </div>
    @if ($canAccessFilamentAdmin)
        <a href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product], isAbsolute: false, panel: 'admin') }}"
            target="_blank" rel="noopener noreferrer" class="group z-30 flex items-center gap-2">
            <x-icon name="edit"
                class="size-6 text-zinc-700 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
            <span
                class="whitespace-nowrap text-zinc-800 group-hover:text-brand-red hidden md:block">Редактировать</span>
        </a>
    @endif
</div>

@pushOnce('scripts', 'product-share-action')
    <script>
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('.js-share-product');

            if (!button) {
                return;
            }

            const shareData = {
                title: button.dataset.title || document.title,
                text: button.dataset.text || button.dataset.title || document.title,
                url: button.dataset.url || window.location.href,
            };

            if (navigator.share) {
                try {
                    await navigator.share(shareData);

                    return;
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        return;
                    }

                    console.error(error);
                }
            }

            try {
                await navigator.clipboard.writeText(shareData.url);

                const label = button.querySelector('.js-share-product-label');
                const originalLabel = label?.textContent || 'Поделиться';

                button.setAttribute('aria-label', 'Ссылка скопирована');

                if (label) {
                    label.textContent = 'Ссылка скопирована';
                }

                setTimeout(() => {
                    button.setAttribute('aria-label', 'Поделиться');

                    if (label) {
                        label.textContent = originalLabel;
                    }
                }, 2000);
            } catch (error) {
                window.prompt('Скопируйте ссылку:', shareData.url);
            }
        });
    </script>
@endPushOnce
