@extends('emails.layout')
@section('body')
<p>Merhaba {{ $user->name }},</p>
<p>Şifrenizi sıfırlamak için aşağıdaki düğmeye tıklayın. Bağlantı 60 dakika geçerlidir. Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz; şifreniz değişmez.</p>
<p style="margin:24px 0"><a href="{{ $url }}" style="display:inline-block;background:{{ config('saas.brand.color', '#4f46e5') }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Şifremi sıfırla</a></p>
<p style="color:#6b7280;font-size:13px">{{ $url }}</p>
@endsection
