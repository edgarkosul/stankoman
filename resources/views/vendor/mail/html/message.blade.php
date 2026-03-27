<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('company.site_url', config('app.url'))">
{{ config('settings.general.shop_name', config('app.name')) }}
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('settings.general.shop_name', config('app.name')) }}. Все права защищены.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
