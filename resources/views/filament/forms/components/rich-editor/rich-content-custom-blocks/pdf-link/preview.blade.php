@php
    $href = $href ?? null;
    $linkText = $linkText ?? 'PDF документ';
    $sourceLabel = $sourceLabel ?? 'PDF документ';
    $targetLabel = $targetLabel ?? 'Открывается в новой вкладке';
@endphp

<div class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/40">
    <div class="flex items-start gap-3">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">
            <x-icon
                name="pdf"
                class="size-6 [&_.icon-base]:text-current [&_.icon-accent]:text-current"
            />
        </div>

        <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                {{ $linkText }}
            </div>

            <div class="mt-1 flex flex-wrap gap-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                <span>{{ $sourceLabel }}</span>
                <span>{{ $targetLabel }}</span>
            </div>

            @if ($href)
                <div class="mt-2 truncate text-[11px] text-zinc-400 dark:text-zinc-500">
                    {{ $href }}
                </div>
            @endif
        </div>
    </div>
</div>
