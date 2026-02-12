@php
    $sections = $sections ?? [];
@endphp

@foreach ($sections as $section)
    <section class="space-y-3">
        <h2 class="text-lg font-semibold">{{ $section['title'] }}</h2>

        <div class="text-sm text-zinc-700">
            @if (! empty($section['has_content']))
                {!! $section['html'] !!}
            @elseif (! empty($section['empty_text']))
                <p class="text-zinc-500">{{ $section['empty_text'] }}</p>
            @endif
        </div>
    </section>
@endforeach
