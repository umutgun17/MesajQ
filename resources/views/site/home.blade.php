@extends('site.layout')
@section('body')
<div class="wrap hero">
  <span class="kicker">{{ $landing['kicker'] }}</span>
  <h1>{{ $landing['title'] }}</h1>
  <p class="lead">{{ $landing['lead'] }}</p>
  <div class="row"><a class="btn p lg" href="/app/kayit">{{ config('saas.billing.trial_days') }} gün ücretsiz dene</a><a class="btn g lg" href="/fiyatlandirma">Fiyatları gör</a></div>
  <p class="hint">Kart bilgisi gerekmez · 5 dakikada kurulum · İstediğiniz zaman iptal</p>
  <div class="logos">@foreach($landing['integrations'] as $i)<span>{{ $i }}</span>@endforeach</div>
</div>

<section id="ozellikler"><div class="wrap">
  <h2 class="s">{{ $landing['features_title'] }}</h2>
  <p class="sub">{{ $landing['features_sub'] }}</p>
  <div class="grid">
    @foreach($landing['features'] as $f)
    <div class="card"><div class="ic">{{ $loop->iteration }}</div><h3>{{ $f[0] }}</h3><p>{{ $f[1] }}</p></div>
    @endforeach
  </div>
</div></section>

<section class="alt"><div class="wrap">
  <h2 class="s">Nasıl çalışır?</h2>
  <p class="sub">Teknik ekip gerekmez. Kaydolun, bağlayın, kullanmaya başlayın.</p>
  <div class="grid steps">
    @foreach($landing['steps'] as $s)
    <div class="card"><h3>{{ $s[0] }}</h3><p>{{ $s[1] }}</p></div>
    @endforeach
  </div>
</div></section>

<section id="fiyatlar"><div class="wrap">
  <h2 class="s">Şeffaf fiyatlandırma</h2>
  <p class="sub">Hacminize uygun paketi seçin; büyüdükçe bir üst pakete geçin. Sürpriz ücret yok.</p>
  @include('site._plans')
</div></section>

<section class="alt"><div class="wrap" style="max-width:820px">
  <h2 class="s">Sık sorulanlar</h2>
  <div class="faq">
    @foreach($landing['faq'] as $q)
    <details><summary>{{ $q[0] }}</summary><p>{{ $q[1] }}</p></details>
    @endforeach
  </div>
</div></section>

<section><div class="wrap"><div class="cta">
  <h2>{{ $landing['cta_title'] }}</h2>
  <p>{{ $landing['cta_sub'] }}</p>
  <a class="btn p lg" href="/app/kayit">Ücretsiz başla</a>
</div></div></section>
@endsection
