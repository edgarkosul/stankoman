<x-filament-panels::page>
    @php($cards = $this->exportCards())

    <div class="space-y-6">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-zinc-900">Ручная генерация публичных файлов</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">
                Эта страница ставит в очередь пересборку SEO-файлов сайта и фида для Yandex Market.
                После завершения результат придет в уведомления админки, а ниже можно быстро проверить,
                какие файлы уже лежат на диске и когда они обновлялись.
            </p>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach ($cards as $card)
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="border-b border-zinc-200 bg-zinc-50/80 px-5 py-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="space-y-1">
                                <h3 class="text-sm font-semibold text-zinc-900">{{ $card['title'] }}</h3>
                                <p class="text-sm leading-6 text-zinc-600">{{ $card['description'] }}</p>
                            </div>

                            <a
                                href="{{ $card['public_url'] }}"
                                target="_blank"
                                rel="noreferrer"
                                class="inline-flex items-center justify-center rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-700 transition hover:border-zinc-400 hover:text-zinc-900"
                            >
                                Открыть URL
                            </a>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                Ручной запуск
                            </div>
                            <code class="mt-2 block break-all text-sm text-zinc-900">{{ $card['command'] }}</code>
                        </div>

                        <div class="space-y-3">
                            @foreach ($card['files'] as $file)
                                <div class="rounded-xl border border-zinc-200 px-4 py-3">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0 space-y-1">
                                            <h4 class="text-sm font-medium text-zinc-900">{{ $file['label'] }}</h4>
                                            <p class="break-all font-mono text-xs text-zinc-500">{{ $file['path'] }}</p>

                                            @if (filled($file['note']))
                                                <p class="text-xs text-zinc-600">{{ $file['note'] }}</p>
                                            @endif
                                        </div>

                                        <span @class([
                                            'inline-flex w-fit items-center rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                            'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' => $file['exists'],
                                            'bg-amber-50 text-amber-700 ring-1 ring-amber-200' => ! $file['exists'],
                                        ])>
                                            {{ $file['exists'] ? 'Файл найден' : 'Файла нет' }}
                                        </span>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                                        <span>Обновлен: {{ $file['updated_at'] ?? '—' }}</span>
                                        <span>Размер: {{ $file['size'] ?? '—' }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
