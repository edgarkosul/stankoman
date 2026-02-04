@php
    $rutubeId = $rutubeId ?? null;
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
    $alignment = in_array($alignment ?? null, ['left', 'center'], true) ? $alignment : 'center';
    $alignmentClass = $alignment === 'center' ? 'video--center' : 'video--left';
@endphp

@if ($rutubeId)
    <div
        class="video video-rutube {{ $alignmentClass }}"
        @if ($width) style="max-width: min(100%, {{ $width }}px);" @endif
    >
        <iframe
            src="https://rutube.ru/play/embed/{{ $rutubeId }}"
            allow="clipboard-write; autoplay"
            allowfullscreen
        ></iframe>
    </div>
@endif
