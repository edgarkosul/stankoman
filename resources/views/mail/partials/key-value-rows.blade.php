@foreach ($rows as $row)
**{{ $row['label'] }}:** @if (! empty($row['url']))[{{ $row['value'] }}]({{ $row['url'] }})@else{{ $row['value'] }}@endif

@endforeach
