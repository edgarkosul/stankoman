<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-6" wire:poll.2s="refreshLastSavedRun">
        @if ($lastSavedRun)
            @php
                $statusLabel = match ($lastSavedRun['status'] ?? '') {
                    'pending' => 'В ожидании',
                    'running' => 'Выполняется',
                    'dry_run' => 'Проверено',
                    'applied' => 'Применено',
                    'completed' => 'Завершен',
                    'failed' => 'Ошибка',
                    'cancelled' => 'Остановлен',
                    default => (string) ($lastSavedRun['status'] ?? '—'),
                };
            @endphp

            <div class="space-y-4 rounded-xl border border-zinc-200 bg-white/60 p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Последний запуск деактивации</h2>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-zinc-500">#{{ $lastSavedRun['id'] ?? '—' }}</span>
                        @if (!empty($lastSavedRun['id']))
                            <a
                                href="{{ url('/admin/import-runs/' . $lastSavedRun['id']) }}"
                                class="font-medium text-primary-600 hover:text-primary-500 hover:underline"
                            >
                                Детальный лог
                            </a>
                        @endif
                    </div>
                </div>

                <div class="grid gap-3 text-xs sm:grid-cols-6 sm:text-sm">
                    <div>
                        <div class="text-zinc-500">Статус</div>
                        <div class="font-semibold text-zinc-900">{{ $statusLabel }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Режим</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['mode'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Поставщик</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['supplier_label'] ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Категория сайта</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['site_category_label'] ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">ID в feed</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['found_urls'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Проверено</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['processed'] ?? 0 }}</div>
                    </div>
                </div>

                <div class="grid gap-3 text-xs sm:grid-cols-4 sm:text-sm">
                    <div>
                        <div class="text-zinc-500">Кандидатов</div>
                        <div class="font-semibold text-amber-800">{{ $lastSavedRun['candidates'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Деактивировано</div>
                        <div class="font-semibold text-red-700">{{ $lastSavedRun['deactivated'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Ошибок</div>
                        <div class="font-semibold text-red-700">{{ $lastSavedRun['errors'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Источник</div>
                        <div class="break-all font-semibold text-zinc-900">{{ $lastSavedRun['source'] ?: '—' }}</div>
                    </div>
                </div>

                @if (($lastSavedRun['no_urls'] ?? false) === true)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 sm:text-sm">
                        В выбранном feed не найдено ни одного offer с external_id.
                    </div>
                @endif

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

    @if (!empty($lastSavedSamples))
        <div class="mt-6 space-y-3 rounded-xl border border-zinc-200 bg-white/60 p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">Кандидаты на деактивацию</h2>
                <div class="text-xs text-zinc-500">
                    Показано: {{ count($lastSavedSamples) }}
                </div>
            </div>

            <div class="max-h-80 overflow-auto rounded-lg border border-zinc-200 bg-zinc-50">
                <table class="min-w-full text-xs sm:text-sm">
                    <thead class="sticky top-0 bg-zinc-100">
                        <tr class="border-b border-zinc-200 text-left text-zinc-600">
                            <th class="px-3 py-2 font-medium">ID</th>
                            <th class="px-3 py-2 font-medium">Товар</th>
                            <th class="px-3 py-2 font-medium">external_id</th>
                            <th class="px-3 py-2 font-medium">Цена</th>
                            <th class="px-3 py-2 font-medium">Категории</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @foreach ($lastSavedSamples as $sample)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 font-semibold text-zinc-900">
                                    {{ $sample['product_id'] ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-zinc-900">
                                    {{ $sample['name'] ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-700">
                                    {{ $sample['external_id'] ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-700">
                                    {{ $sample['price'] ?? 0 }}
                                </td>
                                <td class="px-3 py-2 text-zinc-700">
                                    {{ $sample['categories'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
