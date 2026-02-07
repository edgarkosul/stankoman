<div class="border rounded-md p-3 bg-gray-50 dark:bg-gray-900/40">
    <div class="font-semibold text-sm">
        Hero-слайдер
    </div>

    @if($slider)
        <div class="text-xs text-gray-700 dark:text-gray-200 mt-1">
            {{ $slider->name }}
        </div>
        <div class="text-[11px] text-gray-500">
            Слайдов: {{ $slidesCount }}
        </div>
    @else
        <div class="text-[11px] text-red-500 mt-1">
            Слайдер не выбран или удалён
        </div>
    @endif
</div>
