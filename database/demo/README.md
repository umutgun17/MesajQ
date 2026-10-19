# Demo / Sunum Tenant Verisi

`demo:seed` komutu, sunum ve demo amaçlı bir tenant'ı gerçekçi bir kozmetik
e-ticaret mağazası verisiyle doldurur (kurgusal marka: **Luméa**).

```bash
php artisan demo:seed --tenant=7 --fresh          # sıfırdan kur
php artisan demo:seed --tenant=7 --only=inbox     # tek bölümü tazele
```

- `--fresh` hedef tenant'ın TÜM modül verisini siler, sonra yeniden üretir.
- `--only=` bölüm listesi: catalog, customers, orders, inbox, calls, returns,
  reviews, wheel, marketing, chat, search, rec, social, ads, feed, knowledge,
  extras. (settings/channels/users/catalog/customers/orders her koşuda çalışır
  ama veri varsa yeniden yazmaz, yalnızca belleğe alır — bu yüzden bir bölümü
  tazelemeden önce o bölümün tablolarını elle silmek gerekir.)
- Üretim rastgeleliği sabit tohumludur (`mt_srand(20260907)`), koşular tekrar
  edilebilir.

## Güvenlik

- Komut yalnızca `--tenant` ile verilen tenant'a yazar; adı/slug'ı "test" veya
  "demo" içermeyen tenant'larda açık onay ister.
- Müşteri e-postaları `@example.com` (RFC 2606, yönlendirilemez), telefonlar
  `0555 0xx xx xx` kurgusal aralığındadır.
- Tenant ayarlarında gönderim kill-switch'leri KAPALI bırakılır
  (`order_notify_enabled`, `cart_recovery_enabled`, `wheel_reminder_enabled`,
  `email_enabled`, `sms_enabled`, `whatsapp_enabled`, `push_enabled`,
  `iys.enabled`) → zamanlanmış pazarlama komutları bu tenant'ta SİMÜLASYON
  modunda çalışır, gerçek mesaj gitmez.
- E-ticaret (`ticimax-ecommerce`), çağrı (`netgsm-call`) ve SMS kanalları PASİF
  bırakılır; `crm:sync-*`, `call:sync`, `sms:poll-inbox` bu tenant'ı atlar.
  Mesajlaşma kanalları aktiftir ama kimlik bilgisi olmadığı için ilgili
  senkron komutları uyarı verip geçer.

## Dosyalar

- `catalog.php` — 51 ürün (kategori, fiyat, stok, özellikler, varyantlar)
- `people.php`  — Türkçe isim/şehir havuzu, temsilci kullanıcıları
- `content.php` — konuşma senaryoları, yorumlar, bilgi bankası, hazır cevaplar,
  arama sorguları, chatbot diyalogları, kampanya/strateji metinleri
- Ürün görselleri: `public/demo/test-firma/products/pNNN.jpg` (626×626,
  Freepik ücretsiz önizleme). Katalog `img` alanları bu dosya numaralarını
  gösterir.

## Demo kullanıcıları

| E-posta | Rol | Şifre |
|---|---|---|
| kerem@testfirma.demo | admin (iade yöneticisi) | DemoSunum2026! |
| selin@testfirma.demo | temsilci | DemoSunum2026! |
| burak@testfirma.demo | temsilci | DemoSunum2026! |
| ece@testfirma.demo   | temsilci | DemoSunum2026! |
