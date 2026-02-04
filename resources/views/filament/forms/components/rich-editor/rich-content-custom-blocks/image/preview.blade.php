<div class="border rounded-md p-3 bg-gray-50 dark:bg-gray-900/40">
    <div class="font-semibold text-sm">
        Изображение
    </div>

    @if ($path)
        <div class="text-[11px] text-gray-500 mt-1 truncate">
            Файл: {{ $path }}
        </div>
        @if ($alt)
            <div class="text-[11px] text-gray-500 truncate">
                Alt: {{ $alt }}
            </div>
        @endif
    @else
        <div class="text-[11px] text-red-500 mt-1">
            Файл не выбран
        </div>
    @endif
</div>
