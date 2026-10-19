@extends('emails.layout')
@section('body')
@if($reason === 'trial')
<p>{{ config('saas.brand.name') }} deneme süreniz sona erdi. Verileriniz ve ayarlarınız korunuyor; bir plan seçtiğiniz anda kaldığınız yerden devam edersiniz.</p>
@else
<p>Ödeme alınamadığı için {{ config('saas.brand.name') }} aboneliğiniz askıya alındı. Verileriniz korunuyor; ödemeyi tamamladığınızda hesabınız anında açılır.</p>
@endif
<p style="margin:24px 0"><a href="{{ config('saas.app_base') }}/billing" style="display:inline-block;background:{{ config('saas.brand.color', '#4f46e5') }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Plan seç</a></p>
@endsection
