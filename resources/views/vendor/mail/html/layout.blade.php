<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 680px) {
.mail-shell {
width: 100% !important;
max-width: 100% !important;
}

.content-cell,
.header-cell,
.footer-content {
padding-left: 18px !important;
padding-right: 18px !important;
}

.brand-logo {
width: 220px !important;
}
}

@media only screen and (max-width: 520px) {
.header-brand-column,
.header-contact-column {
display: block !important;
width: 100% !important;
}

.header-contact-column {
padding-top: 14px !important;
}

.header-contact-cell,
.header-meta-line,
.header-meta-line a {
text-align: left !important;
}

.brand-logo {
width: 188px !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body class="mail-body">
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="mail-shell inner-body" align="center" width="640" cellpadding="0" cellspacing="0" role="presentation" style="width: 640px; max-width: 640px;">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
