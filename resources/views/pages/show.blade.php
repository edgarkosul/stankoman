<x-layouts.app title="{{ $page->meta_title ?? $page->title }}">
    <div class="static-page mx-auto max-w-7xl px-4 py-10">
        <h1 class="text-3xl font-semibold tracking-tight">
            {{ $page->title }}
        </h1>

        @php
            $content = blank($page->content) ? '<p></p>' : $page->content;
        @endphp

        <div class="fi-prose mt-8 max-w-none">
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
