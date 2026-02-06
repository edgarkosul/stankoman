@php
    $mapUrl = $mapUrl ?? null;
    $width = is_numeric($width ?? null) && (int) $width > 0 ? (int) $width : null;
    $height = is_numeric($height ?? null) && (int) $height > 0 ? (int) $height : null;
    $alignment = in_array($alignment ?? null, ['left', 'center'], true) ? $alignment : 'center';
    $alignmentClass = $alignment === 'center' ? 'map--center' : 'map--left';

    $styleParts = [];

    if ($width) {
        $styleParts[] = "max-width: min(100%, {$width}px)";
    }

    if ($height) {
        $styleParts[] = "height: {$height}px";
    }

    $styleAttribute = filled($styleParts)
        ? 'style="'.implode('; ', $styleParts).';"'
        : '';
@endphp

@if ($mapUrl)
    <div class="map map-yandex {{ $alignmentClass }}" {!! $styleAttribute !!}>
        <iframe
            src="{{ $mapUrl }}"
            frameborder="0"
            allowfullscreen
            loading="lazy"
        ></iframe>
    </div>
@endif
