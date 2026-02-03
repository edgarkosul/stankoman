@props([
    'title' => null,
    'subtitle' => null,
    'subtitle2' => null,
    'align' => 'left',
    'maxWidth' => 'w-80',
    'tooltipId' => null,
])

@php
    $tooltipId = $tooltipId ?? 'tooltip-' . \Illuminate\Support\Str::uuid();
    $alignmentClasses = match ($align) {
        'center' => 'left-1/2 -translate-x-1/2',
        'right' => '-right-10',
        default => 'left-0',
    };
@endphp

<div {{ $attributes->merge(['class' => 'relative inline-flex group z-50']) }}>
    <span class="inline-flex items-center" aria-describedby="{{ $tooltipId }}">
        {{ $trigger ?? '' }}
    </span>

    <div
        id="{{ $tooltipId }}"
        role="tooltip"
        class="absolute top-full {{ $alignmentClasses }} {{ $maxWidth }} mt-2 rounded bg-white p-4 text-sm text-zinc-900 shadow-lg ring-1 ring-black/5 opacity-0 invisible translate-y-1 scale-95 transition duration-200 ease-out group-hover:visible group-hover:opacity-100 group-hover:translate-y-0 group-hover:scale-100 group-focus-within:visible group-focus-within:opacity-100 group-focus-within:translate-y-0 group-focus-within:scale-100"
    >
        @if ($title)
            <div class="text-base font-semibold leading-tight">{{ $title }}</div>
        @endif
        @if ($subtitle)
            <div class="mt-1 text-base font-semibold leading-tight">{{ $subtitle }}</div>
        @endif
        @if ($subtitle2)
            <div class="mt-1 text-base font-semibold leading-tight">{{ $subtitle2 }}</div>
        @endif
        <div class="mt-3 text-sm leading-relaxed text-zinc-700">
            {{ $slot }}
        </div>
    </div>
</div>
