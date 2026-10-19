<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', config('saas.brand.name') . ' — ' . config('saas.brand.tagline'))</title>
<meta name="description" content="@yield('description', $landing['meta_description'] ?? config('saas.brand.tagline'))">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="canonical" href="{{ url()->current() }}">
<meta property="og:title" content="@yield('title', config('saas.brand.name') . ' — ' . config('saas.brand.tagline'))">
<meta property="og:description" content="@yield('description', $landing['meta_description'] ?? config('saas.brand.tagline'))">
<meta property="og:type" content="website">
<style>
:root{--p:{{ config('saas.brand.color') }};--p-soft:{{ config('saas.brand.color') }}14;--ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--bg:#f8fafc}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:#fff;line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}.wrap{max-width:1120px;margin:0 auto;padding:0 20px}
header{position:sticky;top:0;background:rgba(255,255,255,.9);backdrop-filter:blur(8px);border-bottom:1px solid var(--line);z-index:10}
.nav{display:flex;align-items:center;gap:22px;height:64px}.nav .sp{flex:1}.nav a.l{font-size:14px;font-weight:600;color:#334155}.nav a.l:hover{color:var(--p)}
.brand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:20px;letter-spacing:-.3px}.brand i{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;background:var(--p);color:#fff;font-style:normal;font-size:15px}.brand b{color:var(--p)}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:12px;font-weight:700;font-size:14px;border:1px solid transparent;transition:.15s}
.btn.p{background:var(--p);color:#fff}.btn.p:hover{filter:brightness(1.08)}.btn.g{border-color:var(--line);color:#334155;background:#fff}.btn.g:hover{border-color:#cbd5e1}.btn.lg{padding:14px 26px;font-size:16px}
.hero{padding:88px 0 56px;text-align:center}.hero h1{font-size:clamp(34px,5.4vw,58px);line-height:1.08;letter-spacing:-1px;margin:16px 0}.hero p.lead{font-size:19px;color:var(--muted);max-width:720px;margin:0 auto 30px}
.kicker{display:inline-block;font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--p);background:var(--p-soft);padding:6px 12px;border-radius:999px}
.row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}.hint{font-size:13px;color:var(--muted);margin-top:14px}
.logos{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-top:34px}.logos span{font-size:12px;font-weight:700;color:#94a3b8;border:1px solid var(--line);padding:6px 12px;border-radius:999px}
section{padding:64px 0}section.alt{background:var(--bg)}h2.s{font-size:clamp(26px,3.4vw,38px);letter-spacing:-.6px;margin:0 0 10px;text-align:center}p.sub{text-align:center;color:var(--muted);max-width:640px;margin:0 auto 40px;font-size:16px}
.grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px}.card h3{margin:10px 0 6px;font-size:17px}.card p{margin:0;color:var(--muted);font-size:14px}.card .ic{width:40px;height:40px;border-radius:12px;background:var(--p-soft);color:var(--p);display:flex;align-items:center;justify-content:center;font-weight:900}
.steps{counter-reset:s}.steps .card{position:relative}.steps .card:before{counter-increment:s;content:counter(s);position:absolute;top:18px;right:20px;font-size:40px;font-weight:900;color:var(--p-soft)}
.plans{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));align-items:stretch}.plan{border:1px solid var(--line);border-radius:20px;padding:26px;background:#fff;display:flex;flex-direction:column}.plan.h{border-color:var(--p);box-shadow:0 10px 40px -20px var(--p)}
.plan .tag{font-size:11px;font-weight:800;text-transform:uppercase;color:var(--p);background:var(--p-soft);padding:4px 10px;border-radius:999px;align-self:flex-start}.plan h3{margin:12px 0 4px;font-size:22px}.plan .price{font-size:34px;font-weight:900;letter-spacing:-1px;margin:12px 0 2px}.plan .price small{font-size:13px;color:var(--muted);font-weight:600}
.plan ul{list-style:none;padding:0;margin:16px 0;flex:1}.plan li{font-size:14px;color:#334155;padding:6px 0 6px 26px;position:relative}.plan li:before{content:"✓";position:absolute;left:4px;color:#059669;font-weight:900}
.toggle{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:4px;margin:0 auto 26px}.toggle button{border:0;background:transparent;padding:8px 16px;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer;color:#475569}.toggle button.on{background:#fff;color:var(--ink);box-shadow:0 1px 4px rgba(0,0,0,.08)}.toggle button em{font-style:normal;color:#059669}
.faq details{border-bottom:1px solid var(--line);padding:14px 0}.faq summary{cursor:pointer;font-weight:700;font-size:15px}.faq p{color:var(--muted);font-size:14px;margin:8px 0 0}
.cta{background:var(--ink);color:#fff;border-radius:28px;padding:56px 24px;text-align:center}.cta h2{font-size:clamp(26px,3.4vw,40px);margin:0 0 10px;letter-spacing:-.6px}.cta p{color:#cbd5e1;margin:0 0 24px}
footer{border-top:1px solid var(--line);padding:36px 0;color:var(--muted);font-size:13px}footer .cols{display:flex;gap:28px;flex-wrap:wrap;justify-content:space-between}footer a{margin-right:14px}
.legal{max-width:820px;margin:0 auto;padding:60px 20px}.legal h1{font-size:32px;letter-spacing:-.6px}.legal h2{font-size:20px;margin-top:30px}.legal p,.legal li{color:#334155;font-size:15px}
@media(max-width:720px){.nav a.l{display:none}.hero{padding:56px 0 36px}section{padding:44px 0}}
</style>
</head>
<body>
<header><div class="wrap nav">
  <a class="brand" href="/"><i>{{ mb_substr(config('saas.brand.name'), 0, 1) }}Q</i>{{ rtrim(config('saas.brand.name'), 'Q') }}<b>Q</b></a>
  <span class="sp"></span>
  <a class="l" href="/#ozellikler">Özellikler</a>
  <a class="l" href="/fiyatlandirma">Fiyatlandırma</a>
  <a class="l" href="/iletisim">İletişim</a>
  <a class="l" href="/app/login">Giriş</a>
  <a class="btn p" href="/app/kayit">Ücretsiz dene</a>
</div></header>
@yield('body')
<footer><div class="wrap">
  <div class="cols">
    <div><strong style="color:var(--ink)">{{ config('saas.brand.name') }}</strong> · {{ config('saas.brand.tagline') }}<br>{{ config('saas.brand.legal_name') }} · {{ config('saas.brand.support_email') }}</div>
    <div><a href="/fiyatlandirma">Fiyatlandırma</a><a href="/iletisim">İletişim</a><a href="/kvkk">KVKK</a><a href="/gizlilik">Gizlilik</a><a href="/kullanim-sartlari">Kullanım Şartları</a><a href="/mesafeli-satis">Mesafeli Satış</a></div>
  </div>
  <p style="margin-top:18px">© {{ date('Y') }} {{ config('saas.brand.legal_name') }}. Tüm hakları saklıdır.</p>
</div></footer>
</body>
</html>
