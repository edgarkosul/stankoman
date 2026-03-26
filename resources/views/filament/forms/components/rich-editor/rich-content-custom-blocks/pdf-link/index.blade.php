@php
    $href = $href ?? null;
    $linkText = $linkText ?? 'PDF документ';
    $shouldOpenInNewTab = (bool) ($shouldOpenInNewTab ?? false);
@endphp

@if ($href)
    <div class="fi-not-prose my-5">
        <a
            href="{{ $href }}"
            title="{{ $linkText }}"
            @if ($shouldOpenInNewTab)
                target="_blank"
                rel="noopener noreferrer"
            @endif
            class="group inline-flex max-w-full items-center gap-3 px-4 py-3 text-zinc-600 no-underline
                   motion-safe:origin-center motion-safe:transform-gpu motion-safe:transition-transform
                   motion-safe:duration-200 motion-safe:ease-out hover:scale-[1.015] hover:text-zinc-900"
        >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl transition">
                <x-icon
                    name="pdf"
                    class="size-7 [&_.icon-base]:text-current [&_.icon-accent]:text-rose-600"
                />
            </span>

            <span class="min-w-0 truncate text-sm font-semibold">
                {{ $linkText }}
            </span>
        </a>
    </div>
@endif
