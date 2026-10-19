@extends('emails.layout')
@section('body')
<p>Merhaba {{ $user->name }},</p>
<p><b>{{ config('saas.brand.name') }}</b>'a hoş geldiniz. {{ config('saas.billing.trial_days') }} günlük deneme süreniz başladı; bu süre boyunca tüm özellikleri ücretsiz kullanabilirsiniz, kart bilgisi istenmez.</p>
<p>Başlamak için:</p>
<ol>
@foreach(config('saas.onboarding', []) as $s)<li><b>{{ $s['title'] }}</b> — {{ $s['desc'] }}</li>@endforeach
</ol>
<p style="margin:24px 0"><a href="{{ config('saas.app_base') }}" style="display:inline-block;background:{{ config('saas.brand.color', '#4f46e5') }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Panele git</a></p>
<p>Takıldığınız yerde {{ config('saas.brand.support_email') }} adresine yazabilirsiniz.</p>
@endsection
