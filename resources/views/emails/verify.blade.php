@extends('emails.layout')
@section('body')
<p>Merhaba {{ $user->name }},</p>
<p>{{ config('saas.brand.name') }} hesabınızı oluşturdunuz. E-posta adresinizi doğrulamak için aşağıdaki düğmeye tıklayın. Bağlantı 3 gün geçerlidir.</p>
<p style="margin:24px 0"><a href="{{ $url }}" style="display:inline-block;background:{{ config('saas.brand.color', '#4f46e5') }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">E-postamı doğrula</a></p>
<p style="color:#6b7280;font-size:13px">Düğme çalışmazsa bu bağlantıyı tarayıcınıza yapıştırın:<br>{{ $url }}</p>
@endsection
