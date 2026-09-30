<div class="fi-not-prose my-6 text-zinc-700">
    <div class="grid gap-2">
        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-zinc-500">
            Режим работы:
        </div>

        <ul class="grid gap-1 text-base text-zinc-900">
            @foreach ($lines as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>

        @if (filled($note))
            <p class="text-sm text-zinc-600">{{ $note }}</p>
        @endif
    </div>
</div>
