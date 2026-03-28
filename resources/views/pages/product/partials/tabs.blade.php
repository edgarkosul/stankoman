@php
    $tabs = collect($tabs ?? [])->values();
    $activeTab = data_get($tabs->first(), 'key');
@endphp

@if ($tabs->isNotEmpty() && filled($activeTab))
    <section
        class="py-6 lg:py-8"
        x-data="{
            active: @js($activeTab),
            switchTab(key) {
                const scrollY = window.scrollY;

                this.active = key;

                this.$nextTick(() => {
                    requestAnimationFrame(() => {
                        window.scrollTo({ top: scrollY });
                    });
                });
            },
        }"
        data-testid="product-tabs"
    >
        <div class="mb-4 overflow-x-auto border-b border-zinc-200">
            <div class="flex min-w-max items-center gap-1">
                @foreach ($tabs as $tab)
                    <button
                        type="button"
                        class="cursor-pointer border-b-2 px-4 py-3 font-medium transition"
                        x-on:click.prevent.stop="switchTab('{{ $tab['key'] }}')"
                        :class="active === '{{ $tab['key'] }}' ? 'border-brand-red text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-700'"
                    >
                        {{ $tab['title'] }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class=" bg-white p-4">
            @foreach ($tabs as $tab)
                <div x-show="active === '{{ $tab['key'] }}'" x-cloak>
                    @if (($tab['type'] ?? null) === 'specs')
                        @include('pages.product.partials.specs', ['specs' => $tab['specs'] ?? [], 'withHeading' => false])
                    @elseif (filled($tab['html'] ?? null))
                        <div class="fi-prose max-w-none text-sm text-zinc-700">
                            {!! Filament\Forms\Components\RichEditor\RichContentRenderer::make($tab['html'])
                                ->plugins([
                                    App\Filament\Forms\Components\RichEditor\Plugins\TextSizeRichContentPlugin::make(),
                                ])->customBlocks([
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\PdfLinkBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\SellerRequisitesBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HeroSliderBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YandexMapBlock::class,
                                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock::class,
                                ])->toUnsafeHtml() !!}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
