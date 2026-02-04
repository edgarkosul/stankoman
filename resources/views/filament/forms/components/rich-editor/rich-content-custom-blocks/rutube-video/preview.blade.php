<div class="border rounded-md p-3 bg-gray-50 dark:bg-gray-900/40">
    <div class="font-semibold text-sm">
        Видео Rutube
    </div>

    @if($rutubeId)
        <div class="text-[11px] text-gray-500 mt-1 truncate">
            ID: {{ $rutubeId }}
        </div>
    @else
        <div class="text-[11px] text-red-500 mt-1">
            ID не задан
        </div>
    @endif
</div>
