@extends('emails.layout')
@section('body')
<p>Ödemeniz alındı, teşekkürler.</p>
<table cellpadding="6" style="border-collapse:collapse;font-size:14px">
<tr><td style="color:#6b7280">Fatura no</td><td><b>{{ $invoice->number }}</b></td></tr>
<tr><td style="color:#6b7280">Açıklama</td><td>{{ $invoice->description }}</td></tr>
<tr><td style="color:#6b7280">Dönem</td><td>{{ $invoice->period_start?->format('d.m.Y') }} – {{ $invoice->period_end?->format('d.m.Y') }}</td></tr>
<tr><td style="color:#6b7280">Tutar</td><td>{{ number_format($invoice->subtotal, 2, ',', '.') }} {{ $invoice->currency }} + KDV %{{ (int) $invoice->tax_rate }} = <b>{{ number_format($invoice->total, 2, ',', '.') }} {{ $invoice->currency }}</b></td></tr>
</table>
<p>e-Fatura/e-Arşiv belgeniz fatura bilgilerinizdeki e-posta adresine ayrıca iletilir.</p>
@endsection
