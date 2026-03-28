<div class="mail-key-value-list">
@foreach ($rows as $row)
<p class="mail-key-value-row">
    <span class="mail-key-value-label">{{ $row['label'] }}:</span>
    @if (! empty($row['url']))
        <a href="{{ $row['url'] }}" class="mail-key-value-link">{{ $row['value'] }}</a>
    @else
        <span class="mail-key-value-value">{{ $row['value'] }}</span>
    @endif
</p>
@endforeach
</div>
