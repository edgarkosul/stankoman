@php
    $sections = $sections ?? [];
@endphp

@foreach ($sections as $section)
    <section class="space-y-3">
        <h2 class="text-lg font-semibold">{{ $section['title'] }}</h2>

        <div class="text-sm text-zinc-700">
            @if (! empty($section['has_content']))
                @php
                    $content = blank($section['html'] ?? null) ? '<p></p>' : $section['html'];
                @endphp

                <div class="fi-prose max-w-none">
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
            @elseif (! empty($section['empty_text']))
                <p class="text-zinc-500">{{ $section['empty_text'] }}</p>
            @endif
        </div>
    </section>
@endforeach
