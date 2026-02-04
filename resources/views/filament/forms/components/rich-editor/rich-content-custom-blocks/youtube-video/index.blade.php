@php
    $videoId = $videoId ?? null;
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
    $alignment = in_array($alignment ?? null, ['left', 'center'], true) ? $alignment : 'center';
    $alignmentClass = $alignment === 'center' ? 'video--center' : 'video--left';
@endphp

@if ($videoId)
    <div
        class="video video-youtube {{ $alignmentClass }}"
        @if ($width) style="max-width: min(100%, {{ $width }}px);" @endif
    >
        <iframe
            src="https://www.youtube.com/embed/{{ $videoId }}"
            title="YouTube video player"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
        ></iframe>
    </div>
@endif
