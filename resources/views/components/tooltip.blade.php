@props([
    'title' => null,
    'subtitle' => null,
    'subtitle2' => null,
    'align' => 'left',
    'maxWidth' => 'w-80',
    'maxWidthPx' => 320,
])

@php
    $placement = match ($align) {
        'right' => 'bottom-end',
        'center' => 'bottom',
        default => 'bottom-start',
    };
@endphp

<div {{ $attributes->merge(['class' => 'relative inline-flex z-50']) }} x-data>
    <span
        class="inline-flex items-center"
        x-tooltip.smart.theme-ks-light="$refs.tooltipContent.innerHTML"
        data-tooltip-placement="{{ $placement }}"
        data-tooltip-max-width="{{ $maxWidthPx }}"
    >
        {{ $trigger ?? '' }}
    </span>

    <template x-ref="tooltipContent">
        <div class="{{ $maxWidth }}">
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
    </template>
</div>
