@php
    $rutubeId = $rutubeId ?? null;
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
@endphp

@if ($rutubeId)
    <div class="video video-rutube" @if ($width) style="max-width: min(100%, {{ $width }}px);" @endif>
        <iframe
            src="https://rutube.ru/play/embed/{{ $rutubeId }}"
            allow="clipboard-write; autoplay"
            allowfullscreen
        ></iframe>
    </div>
@endif
