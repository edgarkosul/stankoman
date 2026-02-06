@php
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
    $height = is_numeric($height ?? null) && (int) $height > 0 ? (int) $height : null;
@endphp

<div class="border rounded-md p-3 bg-gray-50 dark:bg-gray-900/40">
    <div class="font-semibold text-sm">
        Карта Яндекс
    </div>

    @if($mapUrl)
        <div class="text-[11px] text-gray-500 mt-1 truncate">
            URL: {{ $mapUrl }}
        </div>
    @else
        <div class="text-[11px] text-red-500 mt-1">
            URL не задан
        </div>
    @endif

    @if($width || $height)
        <div class="text-[11px] text-gray-500 mt-1">
            Размер: {{ $width ?? 'auto' }} × {{ $height ?? 'auto' }} px
        </div>
    @endif
</div>
