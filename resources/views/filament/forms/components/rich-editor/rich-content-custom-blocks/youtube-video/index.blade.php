@php
    $videoId = $videoId ?? null;
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
@endphp

@if ($videoId)
    <div class="video video-youtube" @if ($width) style="max-width: min(100%, {{ $width }}px);" @endif>
        <iframe
            src="https://www.youtube.com/embed/{{ $videoId }}"
            title="YouTube video player"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
        ></iframe>
    </div>
@endif
