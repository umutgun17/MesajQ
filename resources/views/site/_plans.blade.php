@php $disc = (int) round(config('saas.billing.yearly_discount') * 100); @endphp
<div style="text-align:center"><div class="toggle" id="cycle"><button class="on" data-c="m">Aylık</button><button data-c="y">Yıllık <em>−%{{ $disc }}</em></button></div></div>
<div class="plans">
@foreach($plans as $p)
  <div class="plan {{ $p->highlight ? 'h' : '' }}">
    @if($p->highlight)<span class="tag">Önerilen</span>@endif
    <h3>{{ $p->name }}</h3>
    <p style="margin:0;color:var(--muted);font-size:14px;min-height:42px">{{ $p->description }}</p>
    <div class="price"><span data-m="{{ number_format($p->price_monthly, 0, ',', '.') }}" data-y="{{ number_format($p->yearlyPrice() / 12, 0, ',', '.') }}">{{ number_format($p->price_monthly, 0, ',', '.') }}</span> ₺ <small>/ ay + KDV</small></div>
    <div style="font-size:12px;color:var(--muted)" class="ynote" hidden>Yıllık {{ number_format($p->yearlyPrice(), 0, ',', '.') }} ₺ tek ödeme</div>
    <ul>@foreach($p->features as $f)<li>{{ $f }}</li>@endforeach</ul>
    <a class="btn p" href="/app/kayit?plan={{ $p->code }}">{{ $p->trial_days }} gün ücretsiz dene</a>
  </div>
@endforeach
</div>
<p class="hint" style="text-align:center">Deneme süresince kart istenmez. Fiyatlara KDV dahil değildir; kartla ya da havale/EFT ile ödeyebilirsiniz.</p>
<script>
document.querySelectorAll('#cycle button').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('#cycle button').forEach(x=>x.classList.remove('on'));b.classList.add('on');const y=b.dataset.c==='y';document.querySelectorAll('.plan .price span').forEach(s=>s.textContent=y?s.dataset.y:s.dataset.m);document.querySelectorAll('.ynote').forEach(n=>n.hidden=!y)}));
</script>
