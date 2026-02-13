<div class="space-y-3">
    @if (! empty($title ?? null))
        <h2 class="text-sm font-semibold">{{ $title }}</h2>
    @endif

    @if (empty($rows))
        <p class="text-sm text-zinc-500">
            Нет записей для отображения.
        </p>
    @else
        <div class="overflow-x-auto rounded-md border">
            <table class="min-w-full text-xs">
                <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Строка</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Текущее имя</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Новое имя</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Артикул</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Бренд</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">ID товара</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t">
                            <td class="px-2 py-1 text-zinc-700">
                                {{ $row['row'] ?? '—' }}
                            </td>
                            <td class="px-2 py-1 font-medium text-zinc-900">
                                {{ $row['name'] ?? '' }}
                            </td>
                            <td class="px-2 py-1 text-zinc-800">
                                {{ $row['new_name'] ?? '—' }}
                            </td>
                            <td class="px-2 py-1 text-zinc-700">
                                {{ $row['sku'] ?? '—' }}
                            </td>
                            <td class="px-2 py-1 text-zinc-700">
                                {{ $row['brand'] ?? '—' }}
                            </td>
                            <td class="px-2 py-1 text-zinc-700">
                                {{ $row['id'] ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
