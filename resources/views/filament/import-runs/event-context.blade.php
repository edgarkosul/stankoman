@if ($json !== null)
    <pre class="max-h-96 overflow-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-800">{{ $json }}</pre>
@else
    <div class="text-sm text-zinc-500">Контекст отсутствует.</div>
@endif
