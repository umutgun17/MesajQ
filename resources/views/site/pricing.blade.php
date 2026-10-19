@extends('site.layout')
@section('title', 'Fiyatlandırma — ' . config('saas.brand.name'))
@section('body')
<div class="wrap hero" style="padding-bottom:20px">
  <span class="kicker">Fiyatlandırma</span>
  <h1>Hacminize göre, aylık ya da yıllık</h1>
  <p class="lead">{{ config('saas.billing.trial_days') }} gün ücretsiz deneyin, sonra size uyan paketle devam edin. Yıllık ödemede %{{ (int) round(config('saas.billing.yearly_discount') * 100) }} indirim.</p>
</div>
<section style="padding-top:10px"><div class="wrap">@include('site._plans')</div></section>
<section class="alt"><div class="wrap" style="max-width:820px">
  <h2 class="s">Fiyatlandırma hakkında</h2>
  <div class="faq">
    <details><summary>Deneme süresi nasıl işliyor?</summary><p>Kayıt olduğunuz anda {{ config('saas.billing.trial_days') }} günlük deneme başlar; önerilen paketin tüm özellikleri açıktır, kart istenmez. Süre bitince plan seçmezseniz hesabınız kilitlenir, verileriniz silinmez.</p></details>
    <details><summary>Limit aşımı ne olur?</summary><p>Limit aşımı hizmeti kesmez; panelde uyarı görürsünüz ve bir sonraki ay üst paketten faturalandırılırsınız.</p></details>
    <details><summary>Nasıl ödeme yapabilirim?</summary><p>Kredi/banka kartı (iyzico güvencesiyle, 3D Secure) ya da havale/EFT. Kartla ödemede abonelik otomatik yenilenir; dilediğiniz zaman dönem sonunda iptal edebilirsiniz.</p></details>
    <details><summary>Fatura kesiliyor mu?</summary><p>Evet. Her ödeme için e-Fatura/e-Arşiv düzenlenir ve fatura bilgilerinizdeki adrese iletilir.</p></details>
    <details><summary>Kurumsal hacimler için?</summary><p>En üst paketin üzerindeki hacimler için {{ config('saas.brand.support_email') }} adresine yazın, aynı gün teklif hazırlayalım.</p></details>
  </div>
</div></section>
@endsection
