<x-layouts.app title="Главная">
    <div class="w-full bg-zinc-50">
        @php
            $content = blank($homePage?->content) ? '<p></p>' : $homePage->content;
        @endphp

        <div class="fi-prose bg-zinc-50 max-w-7xl mx-auto px-2 xs:px-3 sm:px-4 md:px-6">
            {!! Filament\Forms\Components\RichEditor\RichContentRenderer::make($content)->plugins([
                    App\Filament\Forms\Components\RichEditor\Plugins\TextSizeRichContentPlugin::make(),
                ])->customBlocks([
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RutubeVideoBlock::class,
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageBlock::class,
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ImageGalleryBlock::class,
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YoutubeVideoBlock::class,
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HeroSliderBlock::class,
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\YandexMapBlock::class,
                    App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\RawHtmlBlock::class,
                ])->toUnsafeHtml() !!}
        </div>
    </div>

</x-layouts.app>
