@extends('site.layout')
@php
$brand = config('saas.brand.name'); $legal = config('saas.brand.legal_name'); $mail = config('saas.brand.support_email'); $domain = config('saas.brand.domain');
$titles = ['kvkk' => 'KVKK Aydınlatma Metni', 'gizlilik' => 'Gizlilik Politikası', 'kullanim-sartlari' => 'Kullanım Şartları', 'mesafeli-satis' => 'Mesafeli Satış Sözleşmesi'];
@endphp
@section('title', $titles[$page] . ' — ' . $brand)
@section('body')
<div class="legal">
<h1>{{ $titles[$page] }}</h1>
<p><em>Son güncelleme: {{ now()->format('d.m.Y') }}. Bu metin şablon niteliğindedir; yayına almadan önce hukuk danışmanınızla gözden geçirin.</em></p>
@if($page === 'kvkk')
<h2>1. Veri sorumlusu</h2><p>6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") uyarınca kişisel verileriniz, veri sorumlusu sıfatıyla {{ $legal }} ("{{ $brand }}") tarafından aşağıda açıklanan kapsamda işlenmektedir.</p>
<h2>2. İşlenen veriler ve amaçlar</h2><ul><li><b>Hesap verileri</b> (ad, e-posta, telefon, firma adı, fatura bilgileri): hesap oluşturma, kimlik doğrulama, faturalandırma, destek.</li><li><b>Kullanım verileri</b> (giriş kayıtları, IP, sayfa izleri): güvenlik, hizmet kalitesi, hata ayıklama.</li><li><b>Müşteri verileriniz</b> (mağazanızın sipariş, müşteri, mesaj kayıtları): yalnızca sizin talimatınızla ve hizmeti sunmak amacıyla; bu verilerde {{ $brand }} veri işleyen konumundadır.</li></ul>
<h2>3. Aktarım</h2><p>Veriler; barındırma, ödeme (iyzico), e-posta gönderimi ve bağladığınız üçüncü taraf platformlar (Meta, Ticimax, ikas, pazaryerleri, Netgsm vb.) ile yalnızca hizmetin gerektirdiği ölçüde paylaşılır. Yasal zorunluluk halinde yetkili kurumlara aktarılabilir.</p>
<h2>4. Saklama</h2><p>Hesap verileri abonelik süresince ve sona erdikten sonra yasal saklama süreleri boyunca; müşteri verileriniz hesabınız silindikten sonra en geç 30 gün içinde silinir ya da anonimleştirilir.</p>
<h2>5. Haklarınız</h2><p>KVKK 11. madde kapsamındaki taleplerinizi (bilgi, düzeltme, silme, itiraz vb.) {{ $mail }} adresine iletebilirsiniz; başvurular en geç 30 gün içinde yanıtlanır.</p>
@elseif($page === 'gizlilik')
<h2>1. Çerezler</h2><p>{{ $domain }} yalnızca oturum ve tercih çerezleri kullanır; üçüncü taraf reklam çerezi yoktur. Tarayıcınızdan çerezleri silebilir ya da engelleyebilirsiniz.</p>
<h2>2. Güvenlik</h2><p>Veriler TLS ile şifreli aktarılır; API anahtarları ve kart tokenları şifreli saklanır. Kart bilgileri {{ $brand }} sunucularında tutulmaz, ödeme kuruluşu (iyzico) tarafından saklanır.</p>
<h2>3. Alt işleyiciler</h2><p>Barındırma (Türkiye/AB veri merkezleri), ödeme (iyzico), yapay zekâ servisleri (yalnızca AI özellikleri etkinse ve yalnız ilgili içerik), e-posta/SMS sağlayıcıları.</p>
<h2>4. İletişim</h2><p>Gizlilikle ilgili sorularınız için {{ $mail }}.</p>
@elseif($page === 'kullanim-sartlari')
<h2>1. Taraflar ve kapsam</h2><p>Bu şartlar, {{ $legal }} tarafından sunulan {{ $brand }} hizmetine ({{ $domain }}) kayıt olan tüm kullanıcılar için geçerlidir. Kayıt olarak bu şartları kabul etmiş sayılırsınız.</p>
<h2>2. Hesap</h2><p>Hesabınızdaki tüm işlemlerden siz sorumlusunuz. Erişim bilgilerinizi gizli tutmalı, yetkisiz kullanımı derhal bildirmelisiniz. Ekibinize açtığınız kullanıcılar sizin sorumluluğunuzdadır.</p>
<h2>3. Abonelik ve ödeme</h2><p>Hizmet deneme süresinin ardından seçilen plana göre aylık ya da yıllık ücrete tabidir. Ücretler KDV hariçtir. Kartla ödemelerde abonelik dönem sonunda otomatik yenilenir; iptal, dönem sonunda geçerli olur ve ödenmiş dönem için iade yapılmaz. Ödeme alınamazsa {{ config('saas.billing.grace_days') }} günlük tolerans süresinin ardından hesap kilitlenir; veriler korunur.</p>
<h2>4. Kullanım kuralları</h2><p>Hizmet; spam, yasa dışı içerik, üçüncü kişilerin haklarını ihlal eden ya da bağlı platformların (Meta, pazaryerleri, SMS operatörleri) kurallarına aykırı kullanım için kullanılamaz. Toplu mesaj gönderimlerinde İYS ve ilgili mevzuata uyum kullanıcının sorumluluğundadır.</p>
<h2>5. Veri</h2><p>Mağazanıza ait veriler size aittir. {{ $brand }} bu verileri yalnızca hizmeti sunmak için işler. Hesap kapatıldığında veriler 30 gün içinde silinir.</p>
<h2>6. Hizmet seviyesi ve sorumluluk</h2><p>Hizmet "olduğu gibi" sunulur; planlı bakımlar önceden duyurulur. {{ $brand }}'ın sorumluluğu, her halde son 12 ayda ödenen abonelik bedeliyle sınırlıdır. Üçüncü taraf platform kesintilerinden {{ $brand }} sorumlu değildir.</p>
<h2>7. Değişiklik ve fesih</h2><p>Şartlar güncellenebilir; önemli değişiklikler e-posta ile bildirilir. Taraflar aboneliği dönem sonunda feshedebilir. Kurallara aykırılıkta hesap derhal askıya alınabilir.</p>
<h2>8. Uygulanacak hukuk</h2><p>Bu şartlar Türk hukukuna tabidir; uyuşmazlıklarda İstanbul mahkemeleri ve icra daireleri yetkilidir.</p>
@else
<h2>1. Taraflar</h2><p><b>Satıcı:</b> {{ $legal }} — {{ $mail }}<br><b>Alıcı:</b> {{ $brand }} hesabına kayıt olan ve plan seçen kullanıcı (fatura bilgilerinde belirtilen gerçek/tüzel kişi).</p>
<h2>2. Konu</h2><p>Alıcının {{ $domain }} üzerinden elektronik ortamda satın aldığı {{ $brand }} yazılım hizmeti aboneliğine ilişkin 6502 sayılı Kanun ve Mesafeli Sözleşmeler Yönetmeliği kapsamındaki hak ve yükümlülükler.</p>
<h2>3. Hizmet ve bedel</h2><p>Seçilen plan, dönem (aylık/yıllık) ve bedel ödeme adımında ve faturada gösterilir; bedellere KDV eklenir. Hizmet, ödeme onaylandığı anda elektronik ortamda ifa edilmeye başlanır.</p>
<h2>4. Cayma hakkı</h2><p>Hizmet, alıcının onayıyla ödeme anında ifasına başlanan elektronik hizmet niteliğindedir; Yönetmelik'in 15/1-ğ maddesi uyarınca ifasına başlanan hizmetlerde cayma hakkı kullanılamaz. Deneme süresi bu nedenle ücretsiz sunulmaktadır.</p>
<h2>5. İptal ve yenileme</h2><p>Abonelik panelden dilediğiniz zaman dönem sonunda iptal edilebilir; yenileme yapılmaz ve ek ücret alınmaz. Ödenmiş dönem için iade yapılmaz.</p>
<h2>6. Uyuşmazlık</h2><p>Tüketici işlemlerinde Tüketici Hakem Heyetleri ve Tüketici Mahkemeleri; ticari işlemlerde İstanbul mahkemeleri yetkilidir.</p>
@endif
</div>
@endsection
