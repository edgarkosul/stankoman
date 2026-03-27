<x-mail::table>
| Позиция | Сумма |
|:--------|------:|
@foreach ($rows as $row)
| {{ $row['label'] }} | @if (! empty($row['strong'])) **{{ $row['value'] }}** @else {{ $row['value'] }} @endif |
@endforeach
</x-mail::table>
