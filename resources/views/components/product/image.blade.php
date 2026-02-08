@props([
    'src' => null,
    'alt' => null,
])

@if ($src)
    <img
        src="{{ $src }}"
        alt="{{ $alt ?? '' }}"
        {{ $attributes->merge(['class' => 'h-auto w-full rounded border border-zinc-200']) }}
    />
@else
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center rounded border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-500']) }}>
        Нет изображения
    </div>
@endif
