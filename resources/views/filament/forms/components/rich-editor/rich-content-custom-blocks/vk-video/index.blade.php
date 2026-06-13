@php
    $oid = $oid ?? null;
    $vkId = $vkId ?? null;
    $hash = $hash ?? null;
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
    $alignment = in_array($alignment ?? null, ['left', 'center'], true) ? $alignment : 'center';
    $alignmentClass = $alignment === 'center' ? 'video--center' : 'video--left';

    $src = null;
    if ($oid && $vkId) {
        $query = ['oid' => $oid, 'id' => $vkId, 'hd' => 2];
        if ($hash) {
            $query['hash'] = $hash;
        }
        $src = 'https://vk.com/video_ext.php?' . http_build_query($query);
    }
@endphp

@if ($src)
    <div
        class="video video-vk {{ $alignmentClass }}"
        @if ($width) style="max-width: min(100%, {{ $width }}px);" @endif
    >
        <iframe
            src="{{ $src }}"
            allow="clipboard-write; autoplay; encrypted-media; fullscreen; picture-in-picture; screen-wake-lock;"
            allowfullscreen
        ></iframe>
    </div>
@endif
