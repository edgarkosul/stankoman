<x-filament-panels::page>
    {{ $this->form }}

    @if ($dryRunTotals)
        <div class="mt-6 space-y-4">
            <div class="rounded-xl border border-zinc-200 bg-white/60 p-4">
                <h2 class="text-sm font-semibold mb-2">Результаты последнего dry-run</h2>

                <div class="grid gap-3 text-xs sm:text-sm sm:grid-cols-5">
                    <div>
                        <div class="text-zinc-500">Создастся</div>
                        <div class="font-semibold text-emerald-700">
                            {{ $dryRunTotals['create'] ?? 0 }}
                        </div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Обновится</div>
                        <div class="font-semibold text-blue-700">
                            {{ $dryRunTotals['update'] ?? 0 }}
                        </div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Без изменений</div>
                        <div class="font-semibold text-zinc-700">
                            {{ $dryRunTotals['same'] ?? 0 }}
                        </div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Конфликтов</div>
                        <div class="font-semibold text-amber-700">
                            {{ $dryRunTotals['conflict'] ?? 0 }}
                        </div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Ошибок</div>
                        <div class="font-semibold text-red-700">
                            {{ $dryRunTotals['error'] ?? 0 }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Таблица создаваемых --}}
            @if (!empty($dryRunPreviewCreate))
                <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-4">
                    <h3 class="text-sm font-semibold text-emerald-800 mb-2">
                        Товары, которые будут созданы ({{ count($dryRunPreviewCreate) }})
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="border-b border-emerald-100 bg-emerald-50">
                                    <th class="px-2 py-1 text-left font-medium">Строка</th>
                                    <th class="px-2 py-1 text-left font-medium">Имя</th>
                                    <th class="px-2 py-1 text-left font-medium">Артикул</th>
                                    <th class="px-2 py-1 text-left font-medium">Бренд</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dryRunPreviewCreate as $row)
                                    <tr class="border-b border-emerald-50">
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['row'] ?? '—' }}</td>
                                        <td class="px-2 py-1 font-medium text-zinc-900">{{ $row['name'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['sku'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['brand'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Таблица обновляемых --}}
            @if (!empty($dryRunPreviewUpdate))
                <div class="rounded-xl border border-blue-100 bg-blue-50/60 p-4">
                    <h3 class="text-sm font-semibold text-blue-800 mb-2">
                        Товары, которые будут обновлены ({{ count($dryRunPreviewUpdate) }})
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="border-b border-blue-100 bg-blue-50">
                                    <th class="px-2 py-1 text-left font-medium">Строка</th>
                                    <th class="px-2 py-1 text-left font-medium">Текущее имя</th>
                                    <th class="px-2 py-1 text-left font-medium">Новое имя</th>
                                    <th class="px-2 py-1 text-left font-medium">Артикул</th>
                                    <th class="px-2 py-1 text-left font-medium">Бренд</th>
                                    <th class="px-2 py-1 text-left font-medium">ID товара</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dryRunPreviewUpdate as $row)
                                    <tr class="border-b border-blue-50">
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['row'] ?? '—' }}</td>
                                        <td class="px-2 py-1 font-medium text-zinc-900">{{ $row['name'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-zinc-800">
                                            {{ $row['new_name'] ?? '—' }}
                                        </td>
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['sku'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['brand'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-zinc-700">
                                            {{ $row['id'] ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Таблица конфликтов --}}
            @if (!empty($dryRunPreviewConflict))
                <div class="rounded-xl border border-amber-100 bg-amber-50/60 p-4">
                    <h3 class="text-sm font-semibold text-amber-800 mb-2">
                        Строки с конфликтом обновления ({{ count($dryRunPreviewConflict) }})
                    </h3>
                    <p class="text-xs text-amber-700 mb-2">
                        Как правило это значит, что после выгрузки товары уже изменились в базе.
                        Сделайте новую выгрузку перед применением этого файла.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="border-b border-amber-100 bg-amber-50">
                                    <th class="px-2 py-1 text-left font-medium">Строка</th>
                                    <th class="px-2 py-1 text-left font-medium">Имя</th>
                                    <th class="px-2 py-1 text-left font-medium">Новое имя</th>
                                    <th class="px-2 py-1 text-left font-medium">Артикул</th>
                                    <th class="px-2 py-1 text-left font-medium">Бренд</th>
                                    <th class="px-2 py-1 text-left font-medium">ID товара</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dryRunPreviewConflict as $row)
                                    <tr class="border-b border-amber-50">
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['row'] ?? '—' }}</td>
                                        <td class="px-2 py-1 font-medium text-zinc-900">{{ $row['name'] ?? '' }}</td>
                                        <td class="px-2 py-1 text-zinc-800">
                                            {{ $row['new_name'] ?? '—' }}
                                        </td>
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['sku'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-zinc-700">{{ $row['brand'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-zinc-700">
                                            {{ $row['id'] ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
