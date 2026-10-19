(function () {
  'use strict';

  /* \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550
     Yunbi AI Chatbot \u2014 storefront sohbet widget'\u0131 (bkz docs/ai-chatbot.md).
     Sa\u011F altta balon; a\u00E7\u0131l\u0131nca AI asistanla sohbet: \u00FCr\u00FCn \u00F6nerisi (kartl\u0131),
     sipari\u015F durumu, SSS; yetersizse m\u00FC\u015Fteri temsilcisine devir (handoff)
     ve personel cevaplar\u0131 polling ile buraya d\u00FC\u015Fer. Kurulum:
       <script src=".../widget/chat.js?v=1" data-tenant="tonnyblack" defer></script>
     \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550 */

  var SCRIPT = document.currentScript;
  if (!SCRIPT) return;
  var TENANT = SCRIPT.getAttribute('data-tenant');
  if (!TENANT) return;
  if (window.__yunbiChatLoaded) return;
  window.__yunbiChatLoaded = true;

  var API = SCRIPT.src.replace(/\/widget\/chat\.js.*$/, '/w/' + TENANT + '/chat');
  var TIMEOUT = 30000;
  /* Tasarim sablonu: modern (varsayilan) | minimal | soft. Panelden tenant bazinda
     secilir (config'ten gelir); data-template attr YALNIZ demo/onizleme icindir. */
  var TPL_ATTR = (SCRIPT.getAttribute('data-template') || '').toLowerCase();
  var TPLS = ['modern', 'minimal', 'soft', 'elegant'];
  var L_ATTR = (SCRIPT.getAttribute('data-launcher') || '').toLowerCase(); // yalniz demo onizleme
  var LNCHS = ['bubble', 'tab'];

  // \u2500\u2500 Metinler (TR) \u2500\u2500
  var T = {
    aiBadge: 'AI',
    subtitle: 'Yapay zeka destekli asistan',
    placeholder: 'Mesaj\u0131n\u0131z\u0131 yaz\u0131n...',
    handoffBanner: 'M\u00FC\u015Fteri temsilcisine aktar\u0131ld\u0131 \u2014 yan\u0131tlar bu pencerede g\u00F6r\u00FCnecek.',
    connError: 'Ba\u011Flant\u0131 sorunu olu\u015Ftu. L\u00FCtfen tekrar deneyin.',
    open: 'Sohbeti a\u00E7',
    close: 'Kapat'
  };

  // \u2500\u2500 Durum \u2500\u2500
  var KEY = 'yb_chat_key_' + TENANT;
  var cfg = null;
  var sessionKey = null;
  var lastId = 0;
  var rendered = {};           // id -> true (dupe engeli)
  var status = 'bot';          // bot | handoff | closed
  var pollTimer = null;
  var isOpen = false;
  var busy = false;
  var greeted = false;
  var unread = 0;

  try { sessionKey = localStorage.getItem(KEY) || null; } catch (e) {}

  /* Kalici ziyaretci kimligi (kademeli acilim kotasi icin). Oturumdan ayridir:
     ayni kisi yeni sohbet baslatsa da kotada tek kisi sayilir. Bkz ChatRollout. */
  var VKEY = 'yb_chat_vid_' + TENANT;
  var visitorId = null;
  try {
    visitorId = localStorage.getItem(VKEY);
    if (!visitorId) {
      visitorId = 'v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
      localStorage.setItem(VKEY, visitorId);
    }
  } catch (e) { visitorId = null; }

  function saveKey(k) { sessionKey = k; try { localStorage.setItem(KEY, k); } catch (e) {} }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function money(n) { return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' \u20BA'; }
  function priceHTML(p) {
    var dp = parseFloat(p.discounted_price), pr = parseFloat(p.price), now, old = null;
    if (!isNaN(dp) && dp > 0 && !isNaN(pr) && dp < pr) { now = dp; old = pr; }
    else if (!isNaN(pr) && pr > 0) { now = pr; }
    else if (!isNaN(dp) && dp > 0) { now = dp; }
    else return '';
    return '<span class="pr">' + money(now) + '</span>' + (old ? '<span class="old">' + money(old) + '</span>' : '');
  }
  function linkify(s) {
    return esc(s).replace(/(https?:\/\/[^\s<]+)/g, function (u) {
      return '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>';
    });
  }
  /* Tema renginin koyu tonu (soft sablon gradyani icin). amt: 0..1 koyulastirma */
  function shade(hex, amt) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) return hex;
    var n = parseInt(m[1], 16);
    var r = Math.round(((n >> 16) & 255) * (1 - amt));
    var g = Math.round(((n >> 8) & 255) * (1 - amt));
    var b = Math.round((n & 255) * (1 - amt));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }

  // \u2500\u2500 HTTP \u2500\u2500
  function req(method, url, body) {
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var t = setTimeout(function () { if (ctl) ctl.abort(); }, TIMEOUT);
    var opt = { method: method, headers: { 'Accept': 'application/json' }, mode: 'cors', credentials: 'omit', signal: ctl ? ctl.signal : undefined };
    if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(url, opt).then(function (r) {
      clearTimeout(t);
      if (!r.ok) throw new Error('http ' + r.status);
      return r.json();
    });
  }

  // Tiklama eventi: sendBeacon text/plain (preflight yok \u2014 search.js dersi)
  function postEvent(data) {
    try {
      data.sid = sessionKey;
      var payload = JSON.stringify(data);
      var url = API + '/event';
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url, new Blob([payload], { type: 'text/plain;charset=UTF-8' }));
      } else {
        fetch(url, { method: 'POST', mode: 'cors', credentials: 'omit', keepalive: true, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: payload });
      }
    } catch (e) {}
  }

  // \u2500\u2500 DOM (Shadow) \u2500\u2500
  var host = document.createElement('div');
  host.id = 'yunbi-chat-host';
  var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

  var CSS = ''
    + ':host{all:initial}'
    + '*,*:before,*:after{box-sizing:border-box;margin:0;padding:0}'
    + '.wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;position:fixed;z-index:2147483000;right:20px;bottom:20px}'
    + '.bubble{width:58px;height:58px;border-radius:50%;border:none;background:var(--th);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 24px rgba(0,0,0,.24);transition:transform .15s;position:relative}'
    + '.bubble:hover{transform:scale(1.06)}'
    + '.bubble svg{width:28px;height:28px;fill:#fff}'
    + '.dot{position:absolute;top:2px;right:2px;min-width:18px;height:18px;border-radius:9px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;border:2px solid #fff}'
    + '.panel{position:fixed;right:20px;bottom:92px;width:378px;max-width:calc(100vw - 24px);height:600px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 12px 48px rgba(0,0,0,.28);display:none;flex-direction:column;overflow:hidden}'
    + '.panel.on{display:flex}'
    + '.hd{padding:14px 16px;color:#fff;background:var(--th);display:flex;align-items:center;gap:10px}'
    + '.hd .av{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex:0 0 auto}'
    + '.hd .av svg{width:20px;height:20px;fill:#fff}'
    + '.hd .ttl{flex:1;min-width:0}'
    + '.hd .nm{font-size:15px;font-weight:700;display:flex;align-items:center;gap:6px}'
    + '.hd .ai{font-size:9px;font-weight:800;letter-spacing:.5px;background:rgba(255,255,255,.25);border-radius:4px;padding:2px 5px}'
    + '.hd .sub{font-size:11px;opacity:.85;margin-top:2px}'
    + '.hd .x{background:none;border:none;color:#fff;cursor:pointer;font-size:22px;line-height:1;opacity:.85;padding:4px}'
    + '.hd .x:hover{opacity:1}'
    + '.body{flex:1;overflow-y:auto;padding:14px;background:#f6f7f9;display:flex;flex-direction:column;gap:10px}'
    + '.body>*{flex-shrink:0}'
    + '.msg{max-width:82%;padding:9px 13px;border-radius:14px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word}'
    + '.msg a{color:inherit;text-decoration:underline}'
    + '.msg.u{align-self:flex-end;background:var(--th);color:#fff;border-bottom-right-radius:4px}'
    + '.msg.a{align-self:flex-start;background:#fff;color:#1f2937;border:1px solid #e5e7eb;border-bottom-left-radius:4px}'
    + '.msg.g{align-self:flex-start;background:#eef2ff;color:#1f2937;border:1px solid #c7d2fe;border-bottom-left-radius:4px}'
    + '.who{align-self:flex-start;font-size:10.5px;color:#6b7280;margin:-4px 0 -6px 6px}'
    + '.sys{align-self:center;font-size:11px;color:#6b7280;background:#eceef1;border-radius:10px;padding:5px 12px;text-align:center;max-width:92%}'
    /* \u2500\u2500 Urun kartlari: yatay kaydirmali vitrin (snap + ok butonlari) \u2500\u2500 */
    + '.pwrap{align-self:stretch;position:relative}'
    + '.prods{display:flex;gap:8px;overflow-x:auto;padding:2px 0 6px;scroll-snap-type:x proximity;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;scrollbar-width:none}'
    + '.prods::-webkit-scrollbar{height:0;display:none}'
    + '.pc{flex:0 0 146px;scroll-snap-align:start;position:relative;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;cursor:pointer;text-decoration:none;color:#1f2937;transition:box-shadow .14s,transform .14s}'
    + '.prods.few .pc{flex:1 1 0;max-width:190px}'
    + '.pc:hover{box-shadow:0 6px 18px rgba(15,23,42,.14);transform:translateY(-2px)}'
    + '.pc:active{transform:none}'
    + '.pc .imw{position:relative;display:block;background:#f3f4f6}'
    + '.pc .im{width:100%;aspect-ratio:2/3;background:#f3f4f6;display:block;object-fit:cover}'
    + '.pc .disc{position:absolute;top:6px;left:6px;background:#ef4444;color:#fff;font-size:10px;font-weight:800;line-height:1;border-radius:6px;padding:4px 5px;box-shadow:0 2px 6px rgba(0,0,0,.18)}'
    + '.pc .cmp{position:absolute;left:0;right:0;bottom:0;background:linear-gradient(transparent,rgba(0,0,0,.7));color:#fff;font-size:9px;font-weight:800;letter-spacing:.3px;padding:14px 6px 5px;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.pc .oos{position:absolute;inset:0;background:rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center}'
    + '.pc .oos b{background:#111827;color:#fff;font-size:10px;font-weight:700;border-radius:6px;padding:3px 8px}'
    + '.pc .in{display:block;padding:8px 9px 9px}'
    + '.pc .nm{font-size:11.5px;line-height:1.3;height:30px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}'
    + '.pc .prc{display:block;margin-top:5px;font-size:12.5px;font-weight:800;letter-spacing:-.2px}'
    + '.pc .old{font-size:10.5px;color:#9ca3af;text-decoration:line-through;font-weight:400;margin-left:5px}'
    + '.pc .cols{display:flex;align-items:center;gap:3px;margin-top:6px;height:12px}'
    + '.pc .sw{width:11px;height:11px;border-radius:50%;border:1px solid rgba(15,23,42,.18);flex:0 0 auto}'
    + '.pc .swn{font-size:9.5px;color:#9ca3af;font-weight:600;margin-left:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.pnav{position:absolute;top:38%;width:30px;height:30px;border-radius:50%;border:none;background:rgba(255,255,255,.96);box-shadow:0 3px 12px rgba(15,23,42,.22);cursor:pointer;display:none;align-items:center;justify-content:center;z-index:2;padding:0}'
    + '.pnav svg{width:15px;height:15px;fill:#374151}'
    + '.pnav.l{left:-4px}.pnav.r{right:-4px}'
    + '.pnav:hover{background:#fff}'
    + '.pwrap:hover .pnav.on{display:flex}'
    + '@media (hover:none){.pnav{display:none!important}}'
    + '.tpl-elegant .pnav.l{left:32px}'
    + '.qr{align-self:flex-start;display:flex;flex-wrap:wrap;gap:6px;max-width:100%}'
    + '.qrlead{flex:0 0 100%;font-size:11px;color:#6b7280;letter-spacing:.02em;margin:2px 0 1px}'
    + '.qr button{border:1px solid var(--th);color:var(--th);background:#fff;border-radius:16px;padding:6px 12px;font-size:12px;cursor:pointer}'
    + '.qr button:hover{background:var(--th);color:#fff}'
    + '.typing{align-self:flex-start;background:#fff;border:1px solid #e5e7eb;border-radius:14px;border-bottom-left-radius:4px;padding:12px 16px;display:flex;gap:4px}'
    + '.typing i{width:7px;height:7px;border-radius:50%;background:#9ca3af;animation:yb 1.2s infinite}'
    + '.typing i:nth-child(2){animation-delay:.2s}.typing i:nth-child(3){animation-delay:.4s}'
    + '@keyframes yb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-4px)}}'
    + '.banner{background:#fef3c7;color:#92400e;font-size:11.5px;padding:8px 14px;text-align:center;border-top:1px solid #fde68a}'
    + '.ft{display:flex;gap:8px;padding:10px 12px;background:#fff;border-top:1px solid #e5e7eb}'
    + '.ft input{flex:1;border:1px solid #d1d5db;border-radius:22px;padding:10px 15px;font-size:16px;outline:none;color:#111}'
    + '.ft input:focus{border-color:var(--th)}'
    + '.ft button{width:42px;height:42px;border-radius:50%;border:none;background:var(--th);cursor:pointer;display:flex;align-items:center;justify-content:center;flex:0 0 auto}'
    + '.ft button:disabled{opacity:.5;cursor:default}'
    + '.ft button svg{width:18px;height:18px;fill:#fff}'
    /* Mobil: 100vh adres cubugu yuzunden yaniltir \u2014 dvh varsa o kullanilir.
       Alt centik/gesture cubugu icin safe-area payi eklenir. */
    + '@media (max-width:480px){'
    +   '.panel{right:8px;left:8px;width:auto;max-height:none;border-radius:14px;'
    +     'bottom:calc(84px + env(safe-area-inset-bottom,0px));'
    +     'height:calc(100vh - 100px - env(safe-area-inset-bottom,0px));'
    +     'height:calc(100dvh - 100px - env(safe-area-inset-bottom,0px))}'
    +   '.ft{padding-bottom:calc(10px + env(safe-area-inset-bottom,0px))}'
    +   '.prods .pc{flex-basis:46%;max-width:172px}'
    +   '.prods.few .pc{flex:1 1 0;max-width:190px}'
    +   '.wrap{right:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px))}'
    + '}'
    /* \u2500\u2500 Sablon: minimal (koseli, beyaz baslik, tema vurgu cizgisi) \u2500\u2500 */
    + '.tpl-minimal .panel{border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.16);border:1px solid #e5e7eb}'
    + '.tpl-minimal .hd{background:#fff;color:#111827;border-bottom:3px solid var(--th)}'
    + '.tpl-minimal .hd .av{background:var(--th)}'
    + '.tpl-minimal .hd .ai{background:var(--th);color:#fff}'
    + '.tpl-minimal .hd .sub{color:#6b7280;opacity:1}'
    + '.tpl-minimal .hd .x{color:#9ca3af}'
    + '.tpl-minimal .body{background:#fff}'
    + '.tpl-minimal .msg{border-radius:6px}'
    + '.tpl-minimal .msg.a,.tpl-minimal .msg.g{background:#f3f4f6;border:none}'
    + '.tpl-minimal .pc,.tpl-minimal .panel .ft input{border-radius:6px}'
    + '.tpl-minimal .qr button{border-radius:6px}'
    + '.tpl-minimal .bubble{border-radius:14px}'
    + '.tpl-minimal .ft button{border-radius:8px}'
    /* \u2500\u2500 Sablon: soft (ekstra yuvarlak, gradyan baslik) \u2500\u2500 */
    + '.tpl-soft .panel{border-radius:24px;box-shadow:0 16px 56px rgba(0,0,0,.3)}'
    + '.tpl-soft .hd{background:linear-gradient(135deg,var(--th),var(--th2))}'
    + '.tpl-soft .bubble{background:linear-gradient(135deg,var(--th),var(--th2))}'
    + '.tpl-soft .msg{border-radius:20px;padding:10px 15px}'
    + '.tpl-soft .msg.u{border-bottom-right-radius:6px;background:linear-gradient(135deg,var(--th),var(--th2))}'
    + '.tpl-soft .msg.a{border-bottom-left-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:none}'
    + '.tpl-soft .msg.g{border-bottom-left-radius:6px}'
    + '.tpl-soft .pc{border-radius:16px}'
    + '.tpl-soft .qr button{border-radius:20px}'
    + '.tpl-soft .ft button{background:linear-gradient(135deg,var(--th),var(--th2))}'
    + '@media (max-width:480px){.tpl-soft .panel{border-radius:18px}}'
    /* \u2500\u2500 Sablon: elegant (beyaz baslik + renkli avatar + tema-tonlu zemin; premium) \u2500\u2500 */
    + '.tpl-elegant .panel{width:408px;border-radius:18px;border:1px solid #e2e8f0;border-color:color-mix(in srgb,var(--th) 14%,#e5e7eb);box-shadow:0 22px 60px rgba(15,23,42,.24)}'
    + '.tpl-elegant .hd{background:#fff;color:#111827;border-bottom:1px solid #eef0f2;border-bottom-color:color-mix(in srgb,var(--th) 10%,#eef0f2);padding:13px 16px}'
    + '.tpl-elegant .hd .av{background:var(--th);width:40px;height:40px}'
    + '.tpl-elegant .hd .ai{background:var(--th);color:#fff}'
    + '.tpl-elegant .hd .sub{color:#64748b;opacity:1;display:flex;align-items:center;gap:5px}'
    + '.tpl-elegant .hd .sub:before{content:"";width:7px;height:7px;border-radius:50%;background:#34d399;box-shadow:0 0 0 3px rgba(52,211,153,.22);flex:0 0 auto}'
    + '.tpl-elegant .hd .x{color:#94a3b8}'
    + '.tpl-elegant .body{background:#f7f9f8;background:color-mix(in srgb,var(--th) 4%,#fbfcfc);padding:14px 16px}'
    + '.tpl-elegant .msg.a,.tpl-elegant .msg.g{position:relative;margin-left:36px;background:#fff;border:1px solid #e5e7eb;border-color:color-mix(in srgb,var(--th) 12%,#e8ebe9);border-radius:14px;border-top-left-radius:4px;box-shadow:0 1px 2px rgba(15,23,42,.06)}'
    + '.tpl-elegant .msg.a:before,.tpl-elegant .msg.g:before{content:"";position:absolute;left:-36px;top:0;width:26px;height:26px;border-radius:50%;background-color:var(--th);background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23fff\'%3E%3Cpath d=\'M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2zM8.5 12A1.5 1.5 0 0 0 7 13.5 1.5 1.5 0 0 0 8.5 15 1.5 1.5 0 0 0 10 13.5 1.5 1.5 0 0 0 8.5 12zm7 0a1.5 1.5 0 0 0-1.5 1.5 1.5 1.5 0 0 0 1.5 1.5 1.5 1.5 0 0 0 1.5-1.5 1.5 1.5 0 0 0-1.5-1.5z\'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:center;background-size:15px}'
    + '.tpl-elegant .msg.u{border-radius:14px;border-top-right-radius:4px;box-shadow:0 6px 18px color-mix(in srgb,var(--th) 22%,transparent)}'
    + '.tpl-elegant .who{margin-left:42px}'
    + '.tpl-elegant .prods{padding-left:36px}'
    + '.tpl-elegant .pc{border-radius:14px;border-color:color-mix(in srgb,var(--th) 12%,#e8ebe9)}'
    + '.tpl-elegant .qr{margin-left:36px}'
    + '.tpl-elegant .qr button{background:#fff;border-color:color-mix(in srgb,var(--th) 30%,#d6dbd8);font-weight:600}'
    + '.tpl-elegant .ft{border-top-color:color-mix(in srgb,var(--th) 8%,#eef0f2)}'
    + '.tpl-elegant .ft input{border-radius:12px;border-color:color-mix(in srgb,var(--th) 16%,#d7dce1)}'
    + '.tpl-elegant .ft button{border-radius:12px}'
    + '.tpl-elegant .bubble{width:62px;height:62px;box-shadow:0 16px 36px rgba(15,23,42,.24)}'
    + '.tpl-elegant .sys{background:#eef1ef;background:color-mix(in srgb,var(--th) 7%,#eef1ef)}'
    + '@media (max-width:480px){.tpl-elegant .panel{width:auto}}'
    /* \u2500\u2500 Teaser: ilk mesaj kabarcigi (balon yaninda, kapatilabilir \u2014 rue/Pushouse deseni) \u2500\u2500 */
    + '.teaser{position:absolute;right:70px;bottom:4px;width:max-content;max-width:250px;background:#fff;color:#111827;border-radius:14px;border-bottom-right-radius:4px;padding:11px 30px 11px 14px;font-size:12.5px;line-height:1.45;font-weight:500;box-shadow:0 12px 28px rgba(15,23,42,.2);cursor:pointer;animation:ybt .35s ease-out;border:1px solid #eef0f2}'
    + '@keyframes ybt{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}'
    + '.teaser .tx{position:absolute;top:5px;right:5px;width:20px;height:20px;border-radius:50%;border:none;background:#f1f5f9;color:#64748b;font-size:13px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}'
    + '.teaser .tx:hover{background:#e2e8f0}'
    + '.tpl-minimal .teaser{border-radius:6px}'
    + '.tpl-soft .teaser{border-radius:20px;border-bottom-right-radius:6px}'
    + '@media (max-width:480px){.teaser{max-width:210px}}'
    /* \u2500\u2500 Gecmis konusmalar + yeni sohbet (rue/Pushouse deseni) \u2500\u2500 */
    + '.hbtn{background:none;border:none;color:#fff;cursor:pointer;opacity:.8;padding:4px;display:flex;align-items:center;justify-content:center;border-radius:8px}'
    + '.hbtn:hover{opacity:1;background:rgba(255,255,255,.12)}'
    + '.hbtn svg{width:17px;height:17px;fill:currentColor}'
    + '.tpl-minimal .hbtn,.tpl-elegant .hbtn{color:#64748b}'
    + '.tpl-minimal .hbtn:hover,.tpl-elegant .hbtn:hover{background:#f1f5f9}'
    + '.hist{flex:1;overflow-y:auto;background:#f6f7f9;padding:12px;display:none;flex-direction:column;gap:8px}'
    + '.hist.on{display:flex}'
    + '.hist>*{flex-shrink:0}'
    + '.hist .ht{font-size:12px;font-weight:700;color:#374151;padding:2px 4px 4px}'
    + '.hrow{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;cursor:pointer;display:flex;align-items:center;gap:10px;transition:box-shadow .12s;text-align:left;width:100%}'
    + '.hrow:hover{box-shadow:0 2px 10px rgba(0,0,0,.08)}'
    + '.hrow .hi{width:30px;height:30px;border-radius:50%;background:var(--th);opacity:.9;display:flex;align-items:center;justify-content:center;flex:0 0 auto}'
    + '.hrow .hi svg{width:15px;height:15px;fill:#fff}'
    + '.hrow .hc{flex:1;min-width:0}'
    + '.hrow .htt{font-size:12.5px;font-weight:600;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.hrow .hd2{font-size:10.5px;color:#9ca3af;margin-top:2px}'
    + '.hrow .cur{font-size:9px;font-weight:800;color:#fff;background:var(--th);border-radius:4px;padding:2px 5px;flex:0 0 auto}'
    + '.hnew{border:1.5px dashed var(--th);background:#fff;color:var(--th);border-radius:12px;padding:10px 12px;cursor:pointer;font-size:12.5px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px;width:100%}'
    + '.hnew svg{width:15px;height:15px;fill:currentColor}'
    + '.hempty{text-align:center;color:#6b7280;font-size:12px;padding:22px 8px}'
    + '.tpl-minimal .hist{background:#fff}'
    + '.tpl-minimal .hrow,.tpl-minimal .hnew{border-radius:6px}'
    + '.tpl-soft .hrow,.tpl-soft .hnew{border-radius:18px}'
    + '.tpl-elegant .hist{background:color-mix(in srgb,var(--th) 4%,#fbfcfc)}'
    + '.tpl-elegant .hrow{border-color:color-mix(in srgb,var(--th) 12%,#e8ebe9);border-radius:14px}'
    + '.tpl-elegant .hnew{border-radius:14px}'
    /* \u2500\u2500 Bilgi karti (i butonu \u2014 AI seffaflik/KVKK metni) \u2500\u2500 */
    + '.info{position:absolute;inset:0;top:64px;background:rgba(15,23,42,.35);display:none;align-items:flex-start;justify-content:center;padding:18px;z-index:5}'
    + '.info.on{display:flex}'
    + '.info .ib{background:#fff;border-radius:14px;padding:18px;max-width:100%;box-shadow:0 12px 40px rgba(0,0,0,.22);max-height:100%;overflow-y:auto}'
    + '.info .it{font-size:14px;font-weight:800;color:#111827;margin-bottom:8px}'
    + '.info p{font-size:12px;line-height:1.55;color:#374151;margin:0 0 8px}'
    + '.info .iok{margin-top:4px;width:100%;border:none;background:var(--th);color:#fff;border-radius:10px;padding:9px 0;font-size:13px;font-weight:700;cursor:pointer}'
    /* \u2500\u2500 Kutucuk: dikey kenar sekmesi (rue/Pushouse deseni) \u2500\u2500 */
    + '.lmode-tab .bubble{position:fixed;right:0;bottom:50%;transform:translateY(calc(50% + var(--tabshift,0px)));width:44px;height:auto;min-height:192px;border-radius:14px 0 0 14px;flex-direction:column;gap:10px;padding:14px 0;box-shadow:0 10px 32px rgba(15,23,42,.28)}'
    + '.lmode-tab .bubble:hover{transform:translateY(calc(50% + var(--tabshift,0px)));filter:brightness(1.08)}'
    + '.lmode-tab .bubble svg{width:20px;height:20px}'
    + '.lmode-tab .ldot{width:8px;height:8px;border-radius:50%;background:#34d399;box-shadow:0 0 0 3px rgba(52,211,153,.3);flex:0 0 auto}'
    + '.lmode-tab .vt{writing-mode:vertical-rl;transform:rotate(180deg);color:#fff;font-weight:700;font-size:12.5px;letter-spacing:.4px;flex:1;display:flex;align-items:center;max-height:150px;overflow:hidden;white-space:nowrap}'
    + '.lmode-tab .dot{top:-4px;right:6px}'
    + '.lmode-tab .panel{right:56px;bottom:20px}'
    + '.lmode-tab .teaser{position:fixed;right:52px;bottom:auto;top:50%;transform:translateY(calc(-50% + var(--tabshift,0px)));border-bottom-right-radius:14px;border-top-right-radius:4px}'
    + '@media (max-width:480px){.lmode-tab .panel{right:8px}}'
    /* Mobil: kulakcik cark kulakcigiyla ayni dilde — daha dar, daha kucuk yazi/ikon.
       Yukseklik --tabh ile canli olarak carkin kulakcigina esitlenir (syncTabShift). */
    + '@media (max-width:680px){'
    +   '.lmode-tab .bubble{width:var(--tabw,32px);min-height:0;height:var(--tabh,150px);gap:7px;padding:9px 0;border-radius:12px 0 0 12px;box-shadow:0 6px 18px rgba(15,23,42,.22)}'
    +   '.lmode-tab .bubble svg{width:16px;height:16px}'
    +   '.lmode-tab .ldot{width:7px;height:7px;box-shadow:0 0 0 2px rgba(52,211,153,.3)}'
    +   '.lmode-tab .vt{font-size:11.5px;letter-spacing:.2px;max-height:none;overflow:hidden;text-overflow:ellipsis}'
    +   '.lmode-tab .teaser{right:calc(var(--tabw,32px) + 8px);max-width:calc(100vw - var(--tabw,32px) - 24px)}'
    + '}';

  var ICON_CHAT = '<svg viewBox="0 0 24 24"><path d="M12 3C6.48 3 2 6.94 2 11.8c0 2.5 1.18 4.75 3.07 6.35-.13 1.03-.55 2.4-1.53 3.35 0 0 2.34-.16 4.2-1.47.02-.02.05-.03.07-.05 1.3.4 2.72.62 4.19.62 5.52 0 10-3.94 10-8.8S17.52 3 12 3zm-4.5 9.9c-.72 0-1.3-.58-1.3-1.3s.58-1.3 1.3-1.3 1.3.58 1.3 1.3-.58 1.3-1.3 1.3zm4.5 0c-.72 0-1.3-.58-1.3-1.3s.58-1.3 1.3-1.3 1.3.58 1.3 1.3-.58 1.3-1.3 1.3zm4.5 0c-.72 0-1.3-.58-1.3-1.3s.58-1.3 1.3-1.3 1.3.58 1.3 1.3-.58 1.3-1.3 1.3z"/></svg>';
  var ICON_SEND = '<svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
  var ICON_BOT = '<svg viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2zM8.5 12A1.5 1.5 0 0 0 7 13.5 1.5 1.5 0 0 0 8.5 15 1.5 1.5 0 0 0 10 13.5 1.5 1.5 0 0 0 8.5 12zm7 0a1.5 1.5 0 0 0-1.5 1.5 1.5 1.5 0 0 0 1.5 1.5 1.5 1.5 0 0 0 1.5-1.5 1.5 1.5 0 0 0-1.5-1.5z"/></svg>';
  var ICON_NEW = '<svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z"/></svg>';
  var ICON_HIST = '<svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.9 3.9L9 12H6a7 7 0 1 1 7 7v2a9 9 0 0 0 0-18zm-1 5v5l4.3 2.5.7-1.2-3.5-2V8z"/></svg>';
  var ICON_INFO = '<svg viewBox="0 0 24 24"><path d="M12 2A10 10 0 1 0 12 22 10 10 0 0 0 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';

  var els = {};

  function build() {
    var style = document.createElement('style');
    style.textContent = CSS;
    root.appendChild(style);

    var tpl = TPLS.indexOf(TPL_ATTR) >= 0 ? TPL_ATTR : (TPLS.indexOf(cfg.template) >= 0 ? cfg.template : 'modern');
    var lnch = LNCHS.indexOf(L_ATTR) >= 0 ? L_ATTR : (LNCHS.indexOf(cfg.launcher_style) >= 0 ? cfg.launcher_style : 'bubble');
    var wrap = document.createElement('div');
    wrap.className = 'wrap tpl-' + tpl + ' lmode-' + lnch;
    wrap.style.setProperty('--th', cfg.theme_color || '#1f2937');
    wrap.style.setProperty('--th2', shade(cfg.theme_color || '#1f2937', 0.35));

    wrap.innerHTML = ''
      + '<div class="panel" role="dialog" aria-label="' + esc(cfg.bot_name) + '">'
      +   '<div class="hd">'
      +     '<span class="av">' + ICON_BOT + '</span>'
      +     '<span class="ttl"><span class="nm">' + esc(cfg.bot_name) + '<span class="ai">' + T.aiBadge + '</span></span>'
      +     '<span class="sub">' + T.subtitle + '</span></span>'
      +     '<button class="hbtn hinfobtn" title="Asistan hakk\u0131nda" aria-label="Asistan hakk\u0131nda">' + ICON_INFO + '</button>'
      +     '<button class="hbtn hhistbtn" title="Ge\u00E7mi\u015F konu\u015Fmalar" aria-label="Ge\u00E7mi\u015F konu\u015Fmalar">' + ICON_HIST + '</button>'
      +     '<button class="x" aria-label="' + T.close + '">\u00D7</button>'
      +   '</div>'
      +   '<div class="info">'
      +     '<div class="ib">'
      +       '<div class="it">' + esc(cfg.bot_name) + ' hakk\u0131nda</div>'
      +       '<p>Bu asistan <b>yapay zeka</b> ile \u00E7al\u0131\u015F\u0131r. \u00DCr\u00FCn \u00F6nerir, sipari\u015F ve kargo durumunuzu sorgular, ma\u011Faza politikalar\u0131 hakk\u0131nda bilgi verir.</p>'
      +       '<p>Verdi\u011Fi \u00F6neriler tahmini niteliktedir ve ba\u011Flay\u0131c\u0131 de\u011Fildir. Fiyat ve stok bilgisinin g\u00FCncel hali her zaman \u00FCr\u00FCn sayfas\u0131nda yer al\u0131r.</p>'
      +       '<p>Sipari\u015F bilgileriniz yaln\u0131zca sipari\u015F numaras\u0131 ve sipari\u015Fte kay\u0131tl\u0131 ileti\u015Fim bilginizle do\u011Fruland\u0131ktan sonra g\u00F6sterilir. Konu\u015Fmalar hizmet kalitesini art\u0131rmak i\u00E7in saklanabilir.</p>'
      +       '<p>Diledi\u011Finiz anda "m\u00FC\u015Fteri temsilcisi" yazarak ger\u00E7ek bir temsilciye ba\u011Flanabilirsiniz.</p>'
      +       '<button class="iok">Anlad\u0131m</button>'
      +     '</div>'
      +   '</div>'
      +   '<div class="hist"></div>'
      +   '<div class="body"></div>'
      +   '<div class="banner" style="display:none">' + T.handoffBanner + '</div>'
      +   '<div class="ft">'
      +     '<input type="text" maxlength="1000" placeholder="' + T.placeholder + '" enterkeyhint="send">'
      +     '<button class="snd" aria-label="G\u00F6nder">' + ICON_SEND + '</button>'
      +   '</div>'
      + '</div>'
      + (lnch === 'tab'
          ? '<button class="bubble" aria-label="' + T.open + '"><span class="ldot"></span><span class="vt">' + esc(cfg.bot_name) + '</span>' + ICON_CHAT + '</button>'
          : '<button class="bubble" aria-label="' + T.open + '">' + ICON_CHAT + '</button>');

    root.appendChild(wrap);
    document.body.appendChild(host);

    els.panel = wrap.querySelector('.panel');
    els.body = wrap.querySelector('.body');
    els.hist = wrap.querySelector('.hist');
    els.banner = wrap.querySelector('.banner');
    els.ft = wrap.querySelector('.ft');
    els.input = wrap.querySelector('.ft input');
    els.send = wrap.querySelector('.snd');
    els.bubble = wrap.querySelector('.bubble');

    els.bubble.addEventListener('click', toggle);
    wrap.querySelector('.x').addEventListener('click', toggle);
    // NOT: "yeni konuşma" butonu artık geçmiş panelinde (openHistory içinde bağlanır).
    // Header'da .hnewbtn YOK — buradaki eski binding null.addEventListener atıp sonraki
    // gönder/Enter binding'lerini öldürüyordu (30 Tem demo bug'ı).
    wrap.querySelector('.hhistbtn').addEventListener('click', openHistory);
    els.info = wrap.querySelector('.info');
    wrap.querySelector('.hinfobtn').addEventListener('click', function () {
      els.info.classList.toggle('on');
    });
    wrap.querySelector('.iok').addEventListener('click', function () {
      els.info.classList.remove('on');
    });
    els.send.addEventListener('click', submit);
    els.input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });

    els.wrap = wrap;
    if (lnch === 'tab') syncTabShift();
    maybeShowTeaser();
  }

  /* Kulakcik cakismasi: cark widget'i da sag kenarda dikey ortali bir kulakcik
     basiyor (.ywheel-tab-right) — ikisi ust uste biniyordu. Cark kulakcigi varsa
     sohbet kulakcigi onun altina kaydirilir (ekrandan tasmayacak sekilde
     kelepcelenir). Cark kulakcigi sonradan gelip gidebildigi icin (gecikme,
     kupon kullanildi -> kaldirilir) DOM izlenir. Cark solda ise kaydirma yok. */
  function syncTabShift() {
    if (!els.wrap || !els.bubble) return;
    if (els.wrap.className.indexOf('lmode-tab') < 0) return; // yalniz kulakcik modu
    var mob = window.innerWidth <= 680;

    /* Mobilde yukseklik carkin kulakcigina esitlenir (cark solda olsa bile) —
       ikisi yan yana ayni boyda dursun. Cark yoksa CSS varsayilani (124px) kalir.
       Bot adi cok kisa/uzun kulakcikta okunaksiz olmasin diye kelepcelenir. */
    if (mob) {
      var any = document.querySelector('.ywheel-tab');
      var ah = any ? (any.offsetHeight || 0) : 0;
      var aw = any ? (any.offsetWidth || 0) : 0;
      if (ah > 0) {
        var cap = Math.round(window.innerHeight * 0.6);     // ekranin yarisindan fazlasini kaplamasin
        els.wrap.style.setProperty('--tabh', Math.max(96, Math.min(ah, cap)) + 'px');
        els.wrap.style.setProperty('--tabw', Math.max(28, Math.min(aw, 44)) + 'px');
      } else {
        els.wrap.style.removeProperty('--tabh');
        els.wrap.style.removeProperty('--tabw');
      }
    } else {
      els.wrap.style.removeProperty('--tabh');
      els.wrap.style.removeProperty('--tabw');
    }

    var wt = document.querySelector('.ywheel-tab-right');
    if (!wt) { els.wrap.style.setProperty('--tabshift', '0px'); return; }

    var mine = els.bubble.offsetHeight || 192;
    var theirs = wt.offsetHeight || 160;
    var want = theirs / 2 + (mob ? 10 : 14) + mine / 2;  // carkin hemen altina
    var room = Math.max(0, (window.innerHeight - mine) / 2 - 8); // ekranda kal
    els.wrap.style.setProperty('--tabshift', Math.min(want, room) + 'px');
  }

  function watchWheelTab() {
    if (!window.MutationObserver) return;
    var t = null;
    new MutationObserver(function () {
      clearTimeout(t);
      t = setTimeout(syncTabShift, 60); // yigin degisiklikte tek hesap
    }).observe(document.body, { childList: true, subtree: false });
  }

  /* Ilk mesaj kabarcigi: greeting_style=teaser ise, daha once sohbet acilmamis ve
     son 7 gunde kapatilmamissa balonun yaninda goster. Tikla -> sohbet acilir. */
  var TKEY = 'yb_chat_teaser_' + TENANT;
  function maybeShowTeaser() {
    if (!cfg || cfg.greeting_style !== 'teaser') return;
    if (sessionKey) return; // zaten konusmus, rahatsiz etme
    try {
      var ts = parseInt(localStorage.getItem(TKEY) || '0', 10);
      if (ts && (Date.now() - ts) < 7 * 24 * 3600 * 1000) return; // 7 gun sus
    } catch (e) {}

    setTimeout(function () {
      if (isOpen || els.wrap.querySelector('.teaser')) return;
      var text = (cfg.teaser_text || cfg.greeting || '');
      if (text.length > 140) text = text.slice(0, 137) + '...';
      var tz = el('<div class="teaser" role="button">' + esc(text) + '<button class="tx" aria-label="' + T.close + '">\u00D7</button></div>');
      tz.addEventListener('click', function (e) {
        if (e.target && e.target.className === 'tx') return;
        hideTeaser(false);
        if (!isOpen) toggle();
      });
      tz.querySelector('.tx').addEventListener('click', function (e) {
        e.stopPropagation();
        hideTeaser(true);
      });
      els.wrap.appendChild(tz);
    }, 1600);
  }
  function hideTeaser(remember) {
    var tz = els.wrap && els.wrap.querySelector('.teaser');
    if (tz) tz.remove();
    if (remember) { try { localStorage.setItem(TKEY, String(Date.now())); } catch (e) {} }
  }

  // \u2500\u2500 Gecmis konusmalar + yeni sohbet \u2500\u2500
  // Konusma listesi tarayicida tutulur (session_key = gizli anahtar, sunucuya
  // liste endpoint'i gerekmez); baslik = ilk kullanici mesaji.
  var HKEY = 'yb_chat_hist_' + TENANT;
  function getHist() { try { return JSON.parse(localStorage.getItem(HKEY) || '[]'); } catch (e) { return []; } }
  function addHist(key, title) {
    try {
      var a = getHist().filter(function (x) { return x.k !== key; });
      a.unshift({ k: key, t: String(title || '').slice(0, 60), d: Date.now() });
      localStorage.setItem(HKEY, JSON.stringify(a.slice(0, 10)));
    } catch (e) {}
  }

  function showView(v) {
    var hist = v === 'hist';
    els.hist.classList.toggle('on', hist);
    els.body.style.display = hist ? 'none' : 'flex';
    els.ft.style.display = hist ? 'none' : 'flex';
    els.banner.style.display = (!hist && status === 'handoff') ? 'block' : 'none';
  }

  function resetChat() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    sessionKey = null;
    try { localStorage.removeItem(KEY); } catch (e) {}
    lastId = 0; rendered = {}; status = 'bot'; unread = 0; refreshDot();
    els.body.innerHTML = '';
  }

  function newChat() {
    hideTeaser(false);
    var hadConversation = !!sessionKey || els.body.querySelectorAll('.msg.u').length > 0;
    resetChat();
    greeted = true;
    if (hadConversation) addSysNote('Yeni sohbet ba\u015Flat\u0131ld\u0131');
    greet();
    showView('chat');
    try { els.input.focus(); } catch (e) {}
  }

  function openHistory() {
    hideTeaser(false);
    var items = getHist();
    var html = '<div class="ht">Ge\u00E7mi\u015F konu\u015Fmalar</div>';
    els.hist.innerHTML = html;

    var nb = el('<button class="hnew">' + ICON_NEW + 'Yeni sohbet ba\u015Flat</button>');
    nb.addEventListener('click', newChat);
    els.hist.appendChild(nb);

    if (!items.length) {
      els.hist.appendChild(el('<div class="hempty">Hen\u00FCz ge\u00E7mi\u015F konu\u015Fman\u0131z yok.</div>'));
    } else {
      items.forEach(function (it) {
        var dt = new Date(it.d);
        var when = dt.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
          + ' ' + dt.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
        var row = el('<button class="hrow"><span class="hi">' + ICON_BOT + '</span>'
          + '<span class="hc"><span class="htt">' + esc(it.t || 'Konu\u015Fma') + '</span>'
          + '<span class="hd2">' + when + '</span></span>'
          + (it.k === sessionKey ? '<span class="cur">AKT\u0130F</span>' : '') + '</button>');
        row.addEventListener('click', function () { loadSession(it.k); });
        els.hist.appendChild(row);
      });
    }
    showView('hist');
  }

  function loadSession(key) {
    resetChat();
    saveKey(key);
    greeted = true;
    showView('chat');
    boot(); // gecmisi yukler; handoff ise polling boot->setStatus ile kurulur
  }

  /* Mobil klavye: acilinca gorsel viewport kuculur ama position:fixed panel
     eski yuksekligini korur \u2014 yazi alani klavyenin altinda kalirdi.
     visualViewport varsa panel yuksekligi ona gore sabitlenir. */
  var vv = window.visualViewport || null;
  function fitViewport() {
    if (!vv || !els.panel || window.innerWidth > 480) {
      if (els.panel) els.panel.style.height = '';
      return;
    }
    if (!isOpen) { els.panel.style.height = ''; return; }
    var h = Math.max(280, vv.height - 100);
    els.panel.style.height = h + 'px';
    scrollEnd();
  }
  if (vv) {
    vv.addEventListener('resize', fitViewport);
    vv.addEventListener('scroll', fitViewport);
  }

  function toggle() {
    isOpen = !isOpen;
    els.panel.classList.toggle('on', isOpen);
    fitViewport();
    if (isOpen) {
      hideTeaser(false);
      unread = 0; refreshDot();
      if (!greeted) { greeted = true; boot(); }
      else { pollOnce(); }
      schedulePoll();
      setTimeout(function () { try { els.input.focus(); } catch (e) {} }, 60);
    } else {
      schedulePoll();
    }
  }

  function refreshDot() {
    var d = els.bubble.querySelector('.dot');
    if (unread > 0) {
      if (!d) { d = document.createElement('span'); d.className = 'dot'; els.bubble.appendChild(d); }
      d.textContent = unread > 9 ? '9+' : String(unread);
    } else if (d) { d.remove(); }
  }

  // \u2500\u2500 Mesaj render \u2500\u2500
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstChild; }
  function scrollEnd() { els.body.scrollTop = els.body.scrollHeight; }

  function addUser(text) {
    els.body.appendChild(el('<div class="msg u">' + esc(text) + '</div>'));
    scrollEnd();
  }

  function addAssistant(m, isAgent) {
    if (m.id && rendered[m.id]) return;
    if (m.id) { rendered[m.id] = true; if (m.id > lastId) lastId = m.id; }

    if (m.role === 'system') {
      if (m.content === 'handoff') { setStatus('handoff'); }
      return;
    }

    if (isAgent) {
      els.body.appendChild(el('<div class="who">' + 'M\u00FC\u015Fteri Temsilcisi' + '</div>'));
    }
    if (m.content) {
      els.body.appendChild(el('<div class="msg ' + (isAgent ? 'g' : 'a') + '">' + linkify(m.content) + '</div>'));
    }

    if (m.products && m.products.length) {
      els.body.appendChild(productRow(m));
      setTimeout(scrollEnd, 300);
    }

    if (m.quick_replies && m.quick_replies.length && status === 'bot') {
      addQuickReplies(m.quick_replies);
    }
    scrollEnd();
  }

  /* \u2500\u2500 Urun vitrini \u2500\u2500
     Kart yalniz ad+fiyat degil: indirim yuzdesi, kampanya rozeti, renk
     swatch'lari ve tukendi perdesi. Musteri sohbetten cikmadan karsilastirabilsin.
     Yatay sirada snap + (masaustunde) ok butonlari; mobilde parmakla kaydirilir. */
  function productRow(m) {
    var wrap = el('<div class="pwrap"></div>');
    var row = el('<div class="prods' + (m.products.length <= 2 ? ' few' : '') + '"></div>');

    m.products.forEach(function (p, i) {
      var pr = parseFloat(p.price), dp = parseFloat(p.discounted_price);
      var off = (isFinite(pr) && isFinite(dp) && dp > 0 && dp < pr) ? Math.round((1 - dp / pr) * 100) : 0;

      var im = p.image
        ? '<img class="im" loading="lazy" decoding="async" src="' + esc(p.image) + '" alt="">'
        : '<span class="im"></span>';
      var over = (off >= 5 ? '<span class="disc">%' + off + '</span>' : '')
        + (p.badge ? '<span class="cmp">' + esc(p.badge) + '</span>' : '')
        + (p.in_stock === false ? '<span class="oos"><b>T\u00FCkendi</b></span>' : '');

      var a = el('<a class="pc" href="' + esc(p.url || '#') + '" target="_blank" rel="noopener"'
        + ' aria-label="' + esc(p.name || '') + '">'
        + '<span class="imw">' + im + over + '</span>'
        + '<span class="in"><span class="nm">' + esc(p.name) + '</span>'
        + '<span class="prc">' + priceHTML(p) + '</span>'
        + swatchHTML(p.variant_colors) + '</span></a>');

      a.addEventListener('click', function () {
        postEvent({ type: 'click', search_id: m.search_id || null, product_id: p.id, position: i + 1 });
      });
      var imEl = a.querySelector('img.im');
      if (imEl) imEl.addEventListener('load', scrollEnd);
      row.appendChild(a);
    });

    wrap.appendChild(row);

    // Ok butonlari: yalniz tasma varsa ve fare destekli cihazda gorunur
    var L = el('<button class="pnav l" type="button" aria-label="\u00D6nceki"><svg viewBox="0 0 24 24"><path d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4-4.6-4.6z"/></svg></button>');
    var R = el('<button class="pnav r" type="button" aria-label="Sonraki"><svg viewBox="0 0 24 24"><path d="M8.6 7.4 10 6l6 6-6 6-1.4-1.4 4.6-4.6z"/></svg></button>');
    function step(dir) { row.scrollLeft += dir * (row.clientWidth * 0.8); }
    L.addEventListener('click', function (e) { e.preventDefault(); step(-1); });
    R.addEventListener('click', function (e) { e.preventDefault(); step(1); });
    function syncNav() {
      var over = row.scrollWidth - row.clientWidth > 8;
      L.classList.toggle('on', over && row.scrollLeft > 4);
      R.classList.toggle('on', over && row.scrollLeft < row.scrollWidth - row.clientWidth - 4);
    }
    row.addEventListener('scroll', syncNav);
    wrap.appendChild(L); wrap.appendChild(R);
    setTimeout(syncNav, 60); setTimeout(syncNav, 600);

    return wrap;
  }

  /* Renk adi \u2192 renk kutucugu. Katalog degerleri bilesik olabilir
     ("Siyah Beyaz", "Kahve Suet"): malzeme kelimeleri atilir, iki renk kalirsa
     kutucuk iki tonlu gosterilir. Taninmayan ad hic gosterilmez (yanlis renk
     gostermektense hic gostermemek dogru). */
  var COLHEX = {
    siyah: '#14171c', beyaz: '#ffffff', krem: '#f4ecd8', ekru: '#ece2cf', bej: '#e4d3b8',
    kahve: '#6f4a2a', taba: '#a75f2b', vizon: '#b09a86', nude: '#e2c5ae', camel: '#c19a6b',
    kirmizi: '#d92626', bordo: '#7a1f2b', pembe: '#ec4899', fusya: '#d6216f', pudra: '#eec9cf',
    somon: '#f2a08a', lila: '#b39ddb', mor: '#7c3aed', mavi: '#2563eb', saks: '#1e5fd0',
    sax: '#1e5fd0', lacivert: '#1f2a5a', yesil: '#2f7d4f', haki: '#6b6f3a', gri: '#9aa0a6',
    fume: '#6d6f73', antrasit: '#3b3f45', sari: '#f5c518', hardal: '#d1a52a',
    turuncu: '#f97316', oranj: '#f97316', gumus: '#c0c4c8', altin: '#d4af37', gold: '#d4af37'
  };
  function deasc(s) {
    return String(s == null ? '' : s).toLowerCase()
      .replace(/\u0131/g, 'i').replace(/\u0130/g, 'i').replace(/\u00E7/g, 'c')
      .replace(/\u011F/g, 'g').replace(/\u00F6/g, 'o').replace(/\u015F/g, 's')
      .replace(/\u00FC/g, 'u');
  }
  function colorStyle(name) {
    var hexes = [];
    deasc(name).split(/[^a-z]+/).forEach(function (w) {
      if (COLHEX[w] && hexes.indexOf(COLHEX[w]) < 0 && hexes.length < 2) hexes.push(COLHEX[w]);
    });
    if (!hexes.length) return null;
    return hexes.length === 1
      ? 'background:' + hexes[0]
      : 'background:linear-gradient(135deg,' + hexes[0] + ' 50%,' + hexes[1] + ' 50%)';
  }
  function swatchHTML(colors) {
    if (!colors || !colors.length) return '';
    var seen = {}, out = [], extra = 0;
    for (var i = 0; i < colors.length; i++) {
      var st = colorStyle(colors[i]);
      if (!st || seen[st]) continue;
      seen[st] = 1;
      if (out.length < 4) {
        out.push('<span class="sw" style="' + st + '" title="' + esc(colors[i]) + '"></span>');
      } else { extra++; }
    }
    if (!out.length) return '';
    // Tek renk varsa nokta yalniz basina anlamsiz kalir \u2014 adini da yaz.
    var tail = extra ? '<span class="swn">+' + extra + '</span>'
      : (out.length === 1 ? '<span class="swn">' + esc(colors[0]) + '</span>' : '');
    return '<span class="cols">' + out.join('') + tail + '</span>';
  }

  /* Cipler: acilista "Sik sorulanlar" menusu, cevap sonrasi takip onerileri.
     Her cipin arkasinda deterministik bir cevap var (bkz ChatIntents). */
  function addQuickReplies(items, lead) {
    clearQuickReplies();
    var qr = el('<div class="qr"></div>');
    if (lead) qr.appendChild(el('<span class="qrlead">' + esc(lead) + '</span>'));
    items.slice(0, 8).forEach(function (q) {
      var b = document.createElement('button');
      b.textContent = q;
      b.addEventListener('click', function () { clearQuickReplies(); send(q); });
      qr.appendChild(b);
    });
    els.body.appendChild(qr);
    scrollEnd();
  }
  function clearQuickReplies() {
    var old = els.body.querySelectorAll('.qr');
    for (var i = 0; i < old.length; i++) old[i].remove();
  }

  function addSysNote(text) {
    els.body.appendChild(el('<div class="sys">' + esc(text) + '</div>'));
    scrollEnd();
  }

  function setStatus(s) {
    status = s;
    els.banner.style.display = s === 'handoff' ? 'block' : 'none';
    schedulePoll();
  }

  function showTyping() {
    hideTyping();
    els.body.appendChild(el('<div class="typing"><i></i><i></i><i></i></div>'));
    scrollEnd();
  }
  function hideTyping() {
    var t = els.body.querySelector('.typing');
    if (t) t.remove();
  }

  // \u2500\u2500 Ak\u0131\u015F \u2500\u2500
  function boot() {
    if (sessionKey) {
      // Ge\u00E7mi\u015Fi geri y\u00FCkle
      req('GET', API + '/messages?session_key=' + encodeURIComponent(sessionKey) + '&after_id=0')
        .then(function (d) {
          if (d.status === 'none' || !d.messages.length) { greet(); return; }
          setStatus(d.status);
          d.messages.forEach(function (m) {
            if (m.role === 'user') {
              if (m.id) { rendered[m.id] = true; if (m.id > lastId) lastId = m.id; }
              addUser(m.content);
            } else {
              addAssistant(m, m.role === 'agent');
            }
          });
        })
        .catch(function () { greet(); });
    } else {
      greet();
    }
  }

  function greet() {
    els.body.appendChild(el('<div class="msg a">' + linkify(cfg.greeting) + '</div>'));
    if (cfg.quick_replies && cfg.quick_replies.length) addQuickReplies(cfg.quick_replies, 'Sık sorulanlar');
    scrollEnd();
  }

  function submit() {
    var text = (els.input.value || '').trim();
    if (!text || busy) return;
    els.input.value = '';
    clearQuickReplies();
    send(text);
  }

  function send(text) {
    if (busy) return;
    busy = true;
    els.send.disabled = true;
    addUser(text);
    if (status !== 'handoff') showTyping();

    var isNewSession = !sessionKey;
    req('POST', API + '/message', {
      session_key: sessionKey,
      visitor_id: visitorId || undefined,
      message: text,
      page_url: location.href,
      sid: sessionKey || undefined
    }).then(function (d) {
      hideTyping();
      if (d.session_key) saveKey(d.session_key);
      if (isNewSession && d.session_key) addHist(d.session_key, text);
      setStatus(d.status || 'bot');
      (d.messages || []).forEach(function (m) { addAssistant(m, m.role === 'agent'); });
    }).catch(function () {
      hideTyping();
      addSysNote(T.connError);
    }).then(function () {
      busy = false;
      els.send.disabled = false;
      try { els.input.focus(); } catch (e) {}
    });
  }

  // \u2500\u2500 Polling (handoff'ta personel cevab\u0131 i\u00E7in) \u2500\u2500
  function schedulePoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (!sessionKey || status !== 'handoff') return;
    var ms = isOpen ? 5000 : 25000;
    pollTimer = setInterval(pollOnce, ms);
  }

  function pollOnce() {
    if (!sessionKey) return;
    req('GET', API + '/messages?session_key=' + encodeURIComponent(sessionKey) + '&after_id=' + lastId)
      .then(function (d) {
        setStatusSilent(d.status);
        (d.messages || []).forEach(function (m) {
          if (m.role === 'user') { if (m.id && m.id > lastId) lastId = m.id; return; }
          if (m.id && rendered[m.id]) return;
          addAssistant(m, m.role === 'agent');
          if (!isOpen && (m.role === 'agent' || m.role === 'assistant')) { unread++; refreshDot(); }
        });
      })
      .catch(function () {});
  }

  function setStatusSilent(s) {
    if (s && s !== status && s !== 'none') setStatus(s);
  }

  // \u2500\u2500 Ba\u015Flat \u2500\u2500
  function init() {
    req('GET', API + '/config' + (visitorId ? '?visitor_id=' + encodeURIComponent(visitorId) : '')).then(function (c) {
      if (!c || !c.enabled) return;
      cfg = c;
      build();
      watchWheelTab();
      window.addEventListener('resize', syncTabShift);
      // Yazi tipi gec yuklenirse cark kulakciginin yuksekligi degisir — bir kez daha olc.
      try {
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(function () { setTimeout(syncTabShift, 60); });
        }
      } catch (e) {}
      setTimeout(syncTabShift, 1200); // cark gec gelirse (tetikleme gecikmesi) emniyet
      // A\u00E7\u0131k handoff oturumu varsa arka planda personel cevab\u0131 dinle
      if (sessionKey) {
        req('GET', API + '/messages?session_key=' + encodeURIComponent(sessionKey) + '&after_id=0')
          .then(function (d) {
            if (d.status === 'handoff') {
              status = 'handoff';
              d.messages.forEach(function (m) { if (m.id && m.id > lastId) { lastId = m.id; } });
              schedulePoll();
            }
          }).catch(function () {});
      }
    }).catch(function () {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
