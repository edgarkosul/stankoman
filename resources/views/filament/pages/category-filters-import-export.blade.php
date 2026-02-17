<x-filament-panels::page>
    {{ $this->form }}

    @if ($lastTotals)
        <div class="mt-6 rounded-xl border border-zinc-200 bg-white/60 p-4">
            <h2 class="mb-2 text-sm font-semibold">
                {{ $lastWriteMode ? 'Результаты последнего применения' : 'Результаты последнего dry-run' }}
                @if ($lastRunId)
                    <span class="text-zinc-500">#{{ $lastRunId }}</span>
                @endif
            </h2>

            <div class="grid gap-3 text-xs sm:text-sm sm:grid-cols-5">
                <div>
                    <div class="text-zinc-500">Проверено</div>
                    <div class="font-semibold text-zinc-900">{{ $lastTotals['scanned'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-zinc-500">Обновлено</div>
                    <div class="font-semibold text-emerald-700">{{ $lastTotals['updated'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-zinc-500">Пропущено</div>
                    <div class="font-semibold text-zinc-700">{{ $lastTotals['skipped'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-zinc-500">Конфликтов</div>
                    <div class="font-semibold text-amber-700">{{ $lastTotals['conflict'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-zinc-500">Ошибок</div>
                    <div class="font-semibold text-red-700">{{ $lastTotals['error'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
