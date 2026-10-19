<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>{{ config('saas.brand.name') }}</title></head>
<body style="margin:0;background:#f4f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5f9;padding:32px 12px">
<tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
<tr><td style="background:{{ config('saas.brand.color', '#4f46e5') }};padding:22px 28px;color:#fff;font-size:20px;font-weight:700;letter-spacing:.2px">{{ config('saas.brand.name') }}</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6">@yield('body')</td></tr>
<tr><td style="padding:18px 28px;background:#f9fafb;color:#6b7280;font-size:12px;line-height:1.5">
{{ config('saas.brand.legal_name') }} · <a href="https://{{ config('saas.brand.domain') }}" style="color:#6b7280">{{ config('saas.brand.domain') }}</a> · {{ config('saas.brand.support_email') }}
</td></tr></table></td></tr></table></body></html>
