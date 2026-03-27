@props(['url'])

@php
    $shopName = config('settings.general.shop_name', config('app.name'));
    $brandLine = trim((string) config('company.brand_line'));
    $legalName = trim((string) config('company.legal_name'));
    $siteUrl = trim((string) config('company.site_url', $url));
    $siteHost = trim((string) config('company.site_host')) ?: preg_replace('#^https?://#', '', $siteUrl);
    $phone = trim((string) config('company.phone'));
    $phoneHref = preg_replace('/\D+/', '', (string) $phone) ?? '';
    $publicEmail = trim((string) config('company.public_email', config('mail.from.address')));
@endphp

<tr>
<td class="header">
<table class="header-shell" align="center" width="640" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-cell">
<table class="header-top" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-brand-column" width="58%" valign="top">
<a href="{{ $siteUrl }}" class="brand-link" target="_blank" rel="noopener">
<span class="brand-mark">IT</span>
<span class="brand-copy">
<span class="brand-name">{{ $shopName }}</span>
@if (filled($brandLine))
<span class="brand-tagline">{{ $brandLine }}</span>
@endif
@if (filled($legalName) && $legalName !== $shopName)
<span class="brand-tagline">{{ $legalName }}</span>
@endif
</span>
</a>
</td>
<td class="header-contact-column" width="42%" valign="top" align="right">
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
