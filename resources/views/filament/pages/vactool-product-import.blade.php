<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-6" wire:poll.2s="refreshLastSavedRun">
        @if ($lastSavedRun)
            @php
                $statusLabel = match ($lastSavedRun['status'] ?? '') {
                    'pending' => 'В ожидании',
                    'dry_run' => 'Проверено',
                    'applied' => 'Применено',
                    'failed' => 'Ошибка',
                    default => (string) ($lastSavedRun['status'] ?? '—'),
                };
            @endphp

            <div class="space-y-4 rounded-xl border border-zinc-200 bg-white/60 p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Последний запуск Vactool</h2>
                    <div class="text-xs text-zinc-500">#{{ $lastSavedRun['id'] ?? '—' }}</div>
                </div>

                @if (($lastSavedRun['is_running'] ?? false) === true)
                    <div class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 sm:text-sm">
                        Идет обработка: {{ $lastSavedRun['processed'] ?? 0 }} / {{ $lastSavedRun['found_urls'] ?? 0 }}
                        ({{ $lastSavedRun['progress_percent'] ?? 0 }}%)
                    </div>
                @endif

                <div class="grid gap-3 text-xs sm:grid-cols-5 sm:text-sm">
                    <div>
                        <div class="text-zinc-500">Статус</div>
                        <div class="font-semibold text-zinc-900">{{ $statusLabel }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Режим</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['mode'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Найдено URL</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['found_urls'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Обработано</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['processed'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Ошибок</div>
                        <div class="font-semibold text-red-700">{{ $lastSavedRun['errors'] ?? 0 }}</div>
                    </div>
                </div>

                <div class="grid gap-3 text-xs sm:grid-cols-6 sm:text-sm">
                    <div>
                        <div class="text-zinc-500">Создано</div>
                        <div class="font-semibold text-emerald-700">{{ $lastSavedRun['created'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Обновлено</div>
                        <div class="font-semibold text-blue-700">{{ $lastSavedRun['updated'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Пропущено</div>
                        <div class="font-semibold text-zinc-700">{{ $lastSavedRun['skipped'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Скачано изображений</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['images_downloaded'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Деривативов</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['derivatives_queued'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Примеры dry-run</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['samples_count'] ?? 0 }}</div>
                    </div>
                </div>

                @if (!empty($lastSavedIssues))
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                        <h3 class="mb-1 text-xs font-semibold text-amber-900 sm:text-sm">Последние ошибки</h3>
                        <ul class="space-y-1 text-xs text-amber-800 sm:text-sm">
                            @foreach ($lastSavedIssues as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($lastSavedRun['finished_at']))
                    <div class="text-xs text-zinc-500 sm:text-sm">
                        Завершен: {{ $lastSavedRun['finished_at'] }}.
                    </div>
                @endif
            </div>
        @elseif ($lastRunId)
            <div class="rounded-xl border border-zinc-200 bg-white/60 p-4 text-sm text-zinc-600">
                Запуск #{{ $lastRunId }} ожидает первого обновления.
            </div>
        @endif
    </div>
</x-filament-panels::page>
