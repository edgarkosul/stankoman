<div class="flex gap-4 sm:gap-6">
    <div class="group z-30 flex items-center gap-2">
        <livewire:pages.product.favorite-toggle
            :product-id="$product->id"
            :variant="'compare'"
            :key="'favorite-show-' . $product->id"
        />
    </div>
    <div class="group z-30 flex items-center gap-2">
        <livewire:pages.product.compare-toggle
            :product-id="$product->id"
            :variant="'compare'"
            :key="'compare-show-' . $product->id"
        />
    </div>
    <div class="group z-30 cursor-pointer flex items-center gap-2">
        <x-icon name="print"
            class="size-6 text-zinc-700 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
        <span class="whitespace-nowrap text-zinc-800 hover:text-brand-red hidden md:block">Печать</span>
    </div>
    <div class="group z-30 cursor-pointer flex items-center gap-2">
        <x-icon name="pdf"
            class="size-6 text-zinc-700 group-hover:[&_.icon-base]:text-zinc-700 group-hover:[&_.icon-accent]:text-rose-600" />
        <span class="whitespace-nowrap text-zinc-800 hover:text-brand-red hidden md:block">Скачать PDF</span>
    </div>
</div>
