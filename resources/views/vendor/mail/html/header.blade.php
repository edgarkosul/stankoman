@props(['url'])

@php
    $shopName = config('settings.general.shop_name', config('app.name'));
    $siteUrl = trim((string) config('company.site_url', $url)) ?: $url;
    $siteHost = trim((string) config('company.site_host')) ?: preg_replace('#^https?://#', '', $siteUrl);
    $phone = trim((string) config('company.phone'));
    $phoneHref = preg_replace('/\D+/', '', (string) $phone) ?? '';
    $publicEmail = trim((string) config('company.public_email', config('mail.from.address')));
    $logoUrl = url('/images/logo.svg');
@endphp

<tr>
<td class="header">
<table class="mail-shell header-shell" align="center" width="640" cellpadding="0" cellspacing="0" role="presentation" style="width: 640px; max-width: 640px;">
<tr>
<td class="header-cell">
<table class="header-top" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-brand-column" width="52%" valign="top">
<a href="{{ $siteUrl }}" class="brand-logo-link" target="_blank" rel="noopener" aria-label="{{ $shopName }}">
<img src="{{ $logoUrl }}" alt="{{ $shopName }}" class="brand-logo" width="216">
</a>
</td>
<td class="header-contact-column" width="48%" valign="top" align="right">
<table class="header-contact-block" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-contact-cell" align="right">
@if (filled($siteHost))
<p class="header-meta-line"><a href="{{ $siteUrl }}" target="_blank" rel="noopener">{{ $siteHost }}</a></p>
@endif
@if (filled($phone))
<p class="header-meta-line"><a href="tel:+{{ $phoneHref }}">{{ $phone }}</a></p>
@endif
@if (filled($publicEmail))
<p class="header-meta-line"><a href="mailto:{{ $publicEmail }}">{{ $publicEmail }}</a></p>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
