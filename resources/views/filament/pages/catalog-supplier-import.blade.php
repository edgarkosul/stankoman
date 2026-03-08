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
                    <h2 class="text-sm font-semibold">Последний запуск {{ $lastSavedRun['supplier_label'] ?? 'поставщика' }}</h2>
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

                @if (($lastSavedRun['is_running'] ?? false) === true)
                    <div class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 sm:text-sm">
                        Идет обработка: {{ $lastSavedRun['processed'] ?? 0 }} / {{ $lastSavedRun['found_urls'] ?? 0 }}
                        ({{ $lastSavedRun['progress_percent'] ?? 0 }}%)
                    </div>
                @endif

                @if (($lastSavedRun['no_urls'] ?? false) === true)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 sm:text-sm">
                        Подходящие URL товаров не найдены.
                    </div>
                @endif

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
                        <div class="text-zinc-500">Раздел</div>
                        <div class="font-semibold text-zinc-900">{{ $lastSavedRun['scope'] ?: 'все' }}</div>
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

                @if (!empty($lastSavedRun['buckets_file']))
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700 sm:text-sm">
                        Файл buckets: {{ $lastSavedRun['buckets_file'] }}
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

    @if (!empty($parsedScopeTree))
        @php
            $previewScopes = array_slice($parsedScopeTree, 0, 300, true);
            $hiddenCount = max(0, count($parsedScopeTree) - count($previewScopes));
        @endphp

        <div class="mt-6 space-y-3 rounded-xl border border-zinc-200 bg-white/60 p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">Разделы поставщика</h2>
                <div class="text-xs text-zinc-500">
                    Всего: {{ count($parsedScopeTree) }}
                </div>
            </div>

            @if (!empty($scopesLoadedSource))
                <div class="text-xs text-zinc-500">
                    Источник: {{ $scopesLoadedSource }}
                    @if (!empty($scopesLoadedAt))
                        · загружено: {{ $scopesLoadedAt }}
                    @endif
                </div>
            @endif

            <div class="max-h-80 overflow-auto rounded-lg border border-zinc-200 bg-zinc-50">
                <table class="min-w-full text-xs sm:text-sm">
                    <thead class="sticky top-0 bg-zinc-100">
                        <tr class="border-b border-zinc-200 text-left text-zinc-600">
                            <th class="px-3 py-2 font-medium">Ключ</th>
                            <th class="px-3 py-2 font-medium">Раздел</th>
                            <th class="px-3 py-2 font-medium">Товаров</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @foreach ($previewScopes as $scope)
                            @php
                                $depth = max(0, (int) ($scope['depth'] ?? 0));
                                $isLeaf = (bool) ($scope['is_leaf'] ?? false);
                                $itemsCount = max(0, (int) ($scope['items_count'] ?? 0));
                            @endphp
                            <tr class="{{ $isLeaf ? 'bg-white/70' : 'bg-transparent' }}">
                                <td class="whitespace-nowrap px-3 py-2 {{ $isLeaf ? 'font-semibold text-zinc-900' : 'text-zinc-500' }}">
                                    {{ $scope['key'] }}
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2" style="padding-left: {{ $depth * 18 }}px;">
                                        <span class="{{ $isLeaf ? 'font-semibold text-zinc-900' : 'text-zinc-700' }}">
                                            {{ $scope['name'] }}
                                        </span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-700">
                                    {{ $itemsCount }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($hiddenCount > 0)
                <div class="text-xs text-zinc-500">
                    Показаны первые {{ count($previewScopes) }} разделов. Осталось скрыто: {{ $hiddenCount }}.
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
