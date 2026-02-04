<x-layouts.app title="{{ $page->meta_title ?? $page->title }}">
    <div class="mx-auto max-w-5xl px-4 py-10">
        <h1 class="text-2xl font-semibold tracking-tight">
            {{ $page->title }}
        </h1>

        @if (!empty($page->meta_description))
            <p class="mt-2 text-sm text-zinc-600">
                {{ $page->meta_description }}
            </p>
        @endif

        <div class="prose prose-zinc mt-8 max-w-none">
            {!! $page->content !!}
        </div>
    </div>
</x-layouts.app>
