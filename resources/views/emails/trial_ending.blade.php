@extends('emails.layout')
@section('body')
<p>{{ config('saas.brand.name') }} deneme süreniz <b>{{ $days }} gün</b> içinde bitiyor.</p>
<p>Kesinti yaşamamak için şimdi bir plan seçin. Deneme süresi bitene kadar ücret alınmaz; plan seçtiğinizde dönem deneme bitiminden itibaren başlar.</p>
<p style="margin:24px 0"><a href="{{ config('saas.app_base') }}/billing" style="display:inline-block;background:{{ config('saas.brand.color', '#4f46e5') }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Planları gör</a></p>
@endsection
