<div class="space-y-3">
    @if ($issues->isEmpty())
        <p class="text-sm text-zinc-500">Issues для этого запуска не найдены.</p>
    @else
        <div class="overflow-x-auto rounded-md border">
            <table class="min-w-full text-xs">
                <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Строка</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Код</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Сообщение</th>
                        <th class="px-2 py-1 text-left font-medium text-zinc-600">Имя из файла</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($issues as $issue)
                        @php
                            $snapshot = $issue->row_snapshot ?? [];
                            $name = is_array($snapshot) ? ($snapshot['name'] ?? '') : '';
                        @endphp
                        <tr class="border-t">
                            <td class="px-2 py-1 text-zinc-700">
                                {{ $issue->row_index ?? '—' }}
                            </td>
                            <td class="px-2 py-1 text-zinc-800">
                                {{ $issue->code }}
                            </td>
                            <td class="px-2 py-1 text-zinc-800">
                                {{ $issue->message }}
                            </td>
                            <td class="px-2 py-1 text-zinc-700">
                                {{ $name }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
