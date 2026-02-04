@php
    $imagesCount = is_countable($images ?? []) ? count($images) : 0;
@endphp

<div class="border rounded-md p-3 bg-gray-50 dark:bg-gray-900/40">
    <div class="font-semibold text-sm">
        Галерея изображений
    </div>

    <div class="text-[11px] text-gray-500 mt-1">
        Изображений: {{ $imagesCount }}
    </div>
</div>
