@php
    $shopName = config('settings.general.shop_name', config('app.name'));
    $siteUrl = config('company.site_url', config('app.url'));
    $siteHost = preg_replace('#^https?://#', '', (string) $siteUrl);
    $phone = config('company.phone');
    $phoneHref = preg_replace('/\D+/', '', (string) $phone) ?? '';
    $address = config('company.legal_addr');
    $publicEmail = config('company.public_email', config('mail.from.address'));
@endphp

<tr>
<td>
<table class="footer-shell" align="center" width="640" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-content">
<p class="footer-heading">{{ $shopName }}</p>
@if (filled($address))
<p class="footer-copy">{{ $address }}</p>
@endif
<p class="footer-link"><a href="{{ $siteUrl }}" target="_blank" rel="noopener">{{ $siteHost }}</a></p>
@if (filled($phone))
<p class="footer-link"><a href="tel:+{{ $phoneHref }}">{{ $phone }}</a></p>
@endif
@if (filled($publicEmail))
<p class="footer-link"><a href="mailto:{{ $publicEmail }}">{{ $publicEmail }}</a></p>
@endif

<table class="footer-legal" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
