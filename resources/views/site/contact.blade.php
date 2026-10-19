@extends('site.layout')
@section('title', 'İletişim — ' . config('saas.brand.name'))
@section('body')
<div class="legal">
  <h1>İletişim</h1>
  <p>Kurulum, fiyatlandırma ya da teknik sorularınız için bize yazın; iş günlerinde aynı gün dönüş yapıyoruz.</p>
  <p><strong>E-posta:</strong> <a href="mailto:{{ config('saas.brand.support_email') }}" style="color:var(--p)">{{ config('saas.brand.support_email') }}</a></p>
  <p><strong>Panel içinden:</strong> Hesap sahibi/yönetici girişinden Abonelik ve Faturalama sayfasındaki destek bağlantısını kullanabilirsiniz.</p>
  <p style="margin-top:30px"><a class="btn p" href="/app/kayit">Ücretsiz dene</a></p>
</div>
@endsection
