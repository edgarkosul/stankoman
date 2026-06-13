<div class="border rounded-md p-3 bg-gray-50">
    <div class="font-semibold text-sm">
        Видео VK
    </div>

    @if($video)
        <div class="text-[11px] text-gray-500 mt-1 truncate">
            oid: {{ $video['oid'] }}, id: {{ $video['id'] }}
        </div>
    @else
        <div class="text-[11px] text-red-500 mt-1">
            Ссылка не задана или не распознана
        </div>
    @endif
</div>
