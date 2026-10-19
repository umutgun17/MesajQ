@extends('emails.layout')
@section('body')
<p>{{ $invoice->number }} numaralı abonelik ödemesi kayıtlı kartınızdan alınamadı{{ $invoice->failure_reason ? ' (' . $invoice->failure_reason . ')' : '' }}.</p>
<p>Hizmetiniz {{ config('saas.billing.grace_days') }} gün boyunca kesintisiz devam eder; bu süre içinde kart bilgilerinizi güncelleyin ya da havale ile ödeyin. Aksi halde hesabınız geçici olarak kilitlenir, verileriniz korunur.</p>
<p style="margin:24px 0"><a href="{{ config('saas.app_base') }}/billing" style="display:inline-block;background:{{ config('saas.brand.color', '#4f46e5') }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Ödeme bilgilerini güncelle</a></p>
@endsection
