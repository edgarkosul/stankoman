<div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
    <div class="grid gap-2">
        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-zinc-500">
            Режим работы (из настроек):
        </div>

        <div class="grid gap-1 text-sm text-zinc-800">
            @foreach ($lines as $line)
                <span>{{ $line }}</span>
            @endforeach
        </div>

        @if (filled($note))
            <div class="text-xs text-zinc-500">{{ $note }}</div>
        @endif
    </div>
</div>
