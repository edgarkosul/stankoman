@php
    use App\Support\CatalogImport\Runs\ImportRunEventProductFieldLabels;

    $created = is_array($context['created'] ?? null) ? $context['created'] : null;
    $changes = is_array($context['changes'] ?? null) ? $context['changes'] : null;
    $otherChangedFields = is_array($context['other_changed_fields'] ?? null) ? $context['other_changed_fields'] : [];
    $deferredChanges = is_array($context['deferred_changes'] ?? null) ? $context['deferred_changes'] : [];
    $media = is_array($context['media'] ?? null)
        ? $context['media']
        : [
            'queued' => $context['media_queued'] ?? null,
            'reused' => $context['media_reused'] ?? null,
            'deduplicated' => $context['media_deduplicated'] ?? null,
        ];
    $fieldLabel = static fn (mixed $field): string => ImportRunEventProductFieldLabels::label($field);
    $translatedOtherChangedFields = ImportRunEventProductFieldLabels::labels($otherChangedFields);
    $translatedDeferredChanges = ImportRunEventProductFieldLabels::labels($deferredChanges);
@endphp

@if ($context !== null)
    <div class="space-y-4">
        @if ($created !== null)
            <div>
                <div class="mb-2 text-sm font-medium text-zinc-900">Создано</div>
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
                    <dl class="space-y-1 text-xs">
                        @foreach ($created as $field => $value)
                            <div class="grid grid-cols-[180px_1fr] gap-2">
                                <dt class="text-zinc-500">{{ $fieldLabel($field) }}</dt>
                                <dd class="text-zinc-800">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : var_export($value, true) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endif

        @if ($changes !== null && $changes !== [])
            <div>
                <div class="mb-2 text-sm font-medium text-zinc-900">Изменено</div>
                <div class="overflow-auto rounded-lg border border-zinc-200">
                    <table class="min-w-full text-xs">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">Поле</th>
                                <th class="px-3 py-2 text-left font-medium">Было</th>
                                <th class="px-3 py-2 text-left font-medium">Стало</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200">
                            @foreach ($changes as $field => $change)
                                <tr>
                                    <td class="px-3 py-2 text-zinc-900">{{ $fieldLabel($field) }}</td>
                                    <td class="px-3 py-2 text-zinc-700">{{ is_array($change['before']) ? json_encode($change['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : var_export($change['before'], true) }}</td>
                                    <td class="px-3 py-2 text-zinc-700">{{ is_array($change['after']) ? json_encode($change['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : var_export($change['after'], true) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($translatedOtherChangedFields !== [])
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-800">
                <span class="font-medium">Дополнительно изменены поля:</span>
                {{ implode(', ', $translatedOtherChangedFields) }}
            </div>
        @endif

        @if ($translatedDeferredChanges !== [])
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-800">
                <span class="font-medium">Отложенные изменения (после синхронизации медиа):</span>
                {{ implode(', ', $translatedDeferredChanges) }}
            </div>
        @endif

        @if (is_array($media) && array_filter($media, fn ($value): bool => $value !== null) !== [])
            <div>
                <div class="mb-2 text-sm font-medium text-zinc-900">Медиа</div>
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-800">
                    queued: {{ (int) ($media['queued'] ?? 0) }},
                    reused: {{ (int) ($media['reused'] ?? 0) }},
                    deduplicated: {{ (int) ($media['deduplicated'] ?? 0) }}
                </div>
            </div>
        @endif

        @if ($json !== null)
            <details class="rounded-lg border border-zinc-200 bg-white">
                <summary class="cursor-pointer px-3 py-2 text-xs text-zinc-600">Сырой JSON</summary>
                <pre class="max-h-96 overflow-auto border-t border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-800">{{ $json }}</pre>
            </details>
        @endif
    </div>
@else
    <div class="text-sm text-zinc-500">Контекст отсутствует.</div>
@endif
