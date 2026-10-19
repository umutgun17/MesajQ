<?php

/**
 * Demo tenant (Test Firma) metin içerikleri — kozmetik e-ticaret senaryoları.
 * Yalnızca `demo:seed` komutu okur.
 *
 * Yer tutucular: {ad} {siparis} {urun} {kargo} {takip} {gun} {tutar} {kod}
 */
return [

// ── Gelen kutusu konuşma senaryoları ────────────────────────────────────────
'conversations' => [
['topic' => 'Kargo takibi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Merhaba, {siparis} numaralı siparişim ne zaman elime ulaşır acaba?'],
    ['a', 'Merhaba {ad} Hanım, siparişiniz {kargo} ile yola çıktı. Takip numaranız {takip}. Tahmini teslim süresi 1-3 iş günü.', true],
    ['c', 'Teşekkür ederim, takip ettim görünüyor 👍'],
]],
['topic' => 'Kargo gecikmesi', 'status' => 'resolved', 'urgent' => true, 'turns' => [
    ['c', 'Siparişim {gun} gündür kargoda görünüyor, hiç hareket yok. Bu ne biçim hizmet?'],
    ['a', 'Çok haklısınız {ad} Bey, yaşadığınız gecikme için özür dileriz. {kargo} ile görüştük, paketiniz transfer merkezinde bekliyormuş. Bugün itibarıyla harekete geçirildi, en geç yarın dağıtıma çıkacak.', false],
    ['c', 'Tamam, takipte olacağım.'],
    ['a', 'Sizi bilgilendirmeye devam edeceğim {ad} Bey. Yaşattığımız aksaklık için ilk siparişinizde geçerli %10 indirim kodu tanımladım: {kod}', false],
    ['c', 'Teşekkür ederim, ilginiz için sağ olun.'],
]],
['topic' => 'Cilt tipi ürün önerisi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Merhaba, karma ciltim var ve T bölgem çok yağlanıyor. Hangi seruma başlamalıyım?'],
    ['a', 'Merhaba {ad} Hanım! Karma ciltler için Niasinamid %10 + Çinko Gözenek Serumu\'nu öneriyoruz — sebum dengesini düzenler ve gözenek görünümünü azaltır. Yanına Matlaştırıcı Nemlendirici Jel Krem çok iyi gider.', true],
    ['c', 'Retinolle birlikte kullanabilir miyim?'],
    ['a', 'Evet ama aynı akşam değil. Niasinamidi sabah, retinolü gece kullanmanızı öneririz. Retinole haftada 2 geceyle başlayıp kademeli artırın.', true],
    ['c', 'Süper, ikisini de sepete ekledim 🙌'],
]],
['topic' => 'Stok sorgusu', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', '{urun} ürünü ne zaman stoğa girecek?'],
    ['a', 'Merhaba {ad} Hanım, bu ürünümüz için yeni parti üretimi devam ediyor; yaklaşık 10 gün içinde stoklarımıza girmesini bekliyoruz. Ürün sayfasındaki "Gelince Haber Ver" butonuna e-postanızı bırakırsanız stoğa girer girmez size bildirim gönderiyoruz.', true],
    ['c', 'Bıraktım, teşekkürler.'],
]],
['topic' => 'İçerik & alerji', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Merhaba, {urun} içinde parfüm var mı? Cildim çok hassas.'],
    ['a', 'Merhaba {ad} Hanım, bu ürünümüz parfüm (fragrance) içermiyor ve dermatolojik olarak test edilmiştir. Yine de ilk kullanımda kol içine küçük bir miktar uygulayıp 24 saat beklemenizi öneririz.', false],
    ['c', 'Çok iyi, tam aradığım şey. Sipariş veriyorum.'],
]],
['topic' => 'İade talebi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Aldığım fondöten tonu bana çok açık geldi, iade edebilir miyim?'],
    ['a', 'Tabii ki {ad} Hanım. Hijyen kuralları gereği makyaj ürünlerinde ambalajı açılmamış olması gerekiyor — ürününüz kullanılmadıysa 30 gün içinde iade edebilirsiniz. İade sayfamızdan sipariş numaranızla talep oluşturabilirsiniz, kargo kodunu size SMS ile gönderiyoruz.', true],
    ['c', 'Denemek için bir kez sürdüm sadece.'],
    ['a', 'Bu durumda iade alamıyoruz ancak size doğru tonu bulmakta yardımcı olabiliriz. Doğal ışıkta çene hattınızda denediğinizde 30 Beige tonunun daha uyumlu olacağını düşünüyorum. Değişim için %20 indirim tanımlayabilirim.', false],
    ['c', 'Olur, o zaman 30 Beige alayım.'],
]],
['topic' => 'İade parası ne zaman yatar', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'İade ettiğim ürünün parası hâlâ yatmadı, {gun} gün oldu.'],
    ['a', 'Merhaba {ad} Hanım, iadeniz depomuza ulaştı ve {tutar} TL tutarındaki iadeniz kartınıza gönderildi. Bankaların kartlara yansıtma süresi 2-7 iş günü arasında değişiyor. Ekstrenizde görünmezse dekontu paylaşabiliriz.', true],
    ['c', 'Dekont paylaşabilir misiniz?'],
    ['a', 'Elbette, iade dekontunuzu e-posta adresinize gönderdim.', false],
]],
['topic' => 'Sipariş iptali', 'status' => 'resolved', 'urgent' => true, 'turns' => [
    ['c', '{siparis} numaralı siparişimi yanlışlıkla iki kez verdim, birini iptal edebilir misiniz?'],
    ['a', 'Merhaba {ad} Bey, kontrol ettim; ikinci siparişiniz henüz kargoya verilmemiş. İptal talebinizi işleme aldım, {tutar} TL tutarındaki ödemeniz iptal edildi.', false],
    ['c', 'Hızlı dönüş için teşekkürler!'],
]],
['topic' => 'Hasarlı ürün', 'status' => 'resolved', 'urgent' => true, 'turns' => [
    ['c', 'Kargo elime ulaştı ama serum şişesi kırılmış, kutunun içi dökülmüş 😞'],
    ['a', 'Çok üzgünüm {ad} Hanım. Kutunun ve ürünün fotoğrafını paylaşabilir misiniz? Hemen ücretsiz yenisini göndereceğiz, ürünü iade etmenize gerek yok.', false],
    ['c', 'Gönderdim fotoğrafları.'],
    ['a', 'Fotoğrafları aldım, teşekkür ederim. Yeni ürününüz bugün kargoya veriliyor, takip numaranızı akşam SMS ile ileteceğim. Yaşattığımız olumsuzluk için bir sonraki siparişinizde geçerli {kod} kodunu tanımladım.', false],
    ['c', 'Çok teşekkür ederim, ilginiz için sağ olun 🌸'],
]],
['topic' => 'Yanlış ürün gönderimi', 'status' => 'resolved', 'urgent' => true, 'turns' => [
    ['c', 'Ben C vitamini serumu sipariş etmiştim ama kutudan retinol serum çıktı.'],
    ['a', 'Kusura bakmayın {ad} Hanım, depoda karışıklık olmuş. Doğru ürününüzü bugün kargoya veriyoruz. Yanlış gelen ürünü de iade etmenize gerek yok, hediyemiz olsun.', false],
    ['c', 'Vay, çok naziksiniz. Teşekkürler!'],
]],
['topic' => 'Eksik ürün', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Siparişimde 3 ürün vardı ama kutudan 2 tanesi çıktı.'],
    ['a', 'Merhaba {ad} Hanım, kontrol ettim; el kreminiz ikinci parça olarak ayrı gönderilmiş. {kargo} takip numarası {takip} ile yarın elinize ulaşacak.', true],
    ['c', 'Anladım, teşekkürler.'],
]],
['topic' => 'Kampanya & indirim kodu', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Instagram\'da gördüğüm %25 indirim kodu çalışmıyor.'],
    ['a', 'Merhaba {ad} Hanım, o kampanyamız dün gece 23:59\'da sona erdi. Ancak size özel {kod} kodunu tanımladım, sepetinizde %20 indirim sağlayacak. 48 saat geçerli.', false],
    ['c', 'Harika, hemen kullanıyorum 💕'],
]],
['topic' => 'Kapıda ödeme', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Kapıda ödeme yapabiliyor muyum?'],
    ['a', 'Evet {ad} Bey, kapıda nakit veya kredi kartıyla ödeme seçeneğimiz mevcut. Kapıda ödeme için 49,90 TL hizmet bedeli ekleniyor. 1.000 TL ve üzeri siparişlerde kargo ücretsiz.', true],
    ['c', 'Anladım, teşekkürler.'],
]],
['topic' => 'Taksit seçenekleri', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Kredi kartına kaç taksit yapıyorsunuz?'],
    ['a', 'Merhaba {ad} Hanım, 750 TL üzeri siparişlerde 3 taksit, 1.500 TL üzeri siparişlerde 6 taksit imkânı sunuyoruz. Ödeme adımında kartınıza uygun tüm seçenekler listeleniyor.', true],
    ['c', 'Süper 👍'],
]],
['topic' => 'Fatura talebi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Merhaba, {siparis} numaralı siparişimin e-arşiv faturasını gönderebilir misiniz?'],
    ['a', 'Tabii {ad} Bey, faturanız kayıtlı e-posta adresinize gönderildi. Ulaşmazsa spam kutunuzu kontrol edebilirsiniz.', false],
    ['c', 'Geldi, teşekkürler.'],
]],
['topic' => 'Adres değişikliği', 'status' => 'resolved', 'urgent' => true, 'turns' => [
    ['c', 'Siparişimi yanlış adrese verdim, değiştirebilir miyiz?'],
    ['a', 'Merhaba {ad} Hanım, siparişiniz henüz kargoya verilmemiş. Yeni adresinizi buraya yazarsanız hemen güncelleyelim.', false],
    ['c', 'Kadıköy Caferağa Mah. Moda Cad. No:41 D:5 İstanbul'],
    ['a', 'Adresiniz güncellendi, siparişiniz yeni adrese gönderilecek. İyi günler dileriz {ad} Hanım 🌸', false],
]],
['topic' => 'Hediye paketi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Hediye olarak alıyorum, üzerine not yazdırabilir miyim?'],
    ['a', 'Elbette {ad} Hanım! Sepet adımında "Hediye paketi" kutusunu işaretleyip notunuzu yazabilirsiniz. Hediye paketi ücretsizdir ve fatura pakete konmaz.', true],
    ['c', 'Mükemmel, teşekkürler!'],
]],
['topic' => 'Ton seçimi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Fondötende hangi tonu almalıyım? Açık tenliyim ama sarı alt tonum var.'],
    ['a', 'Merhaba {ad} Hanım, sarı alt tonlu açık ten için 20 Ivory tonunu öneriyorum. Emin olamazsanız iki tonu da sipariş edip uymayanı 30 gün içinde iade edebilirsiniz.', true],
    ['c', 'Öyle yapayım o zaman.'],
]],
['topic' => 'Orijinallik', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Ürünler orijinal mi? Çok uygun fiyatlı görünüyor.'],
    ['a', 'Merhaba {ad} Hanım, tüm ürünlerimiz kendi markamız Luméa\'ya ait ve üretici firmadan doğrudan tedarik ediliyor. Her ürünün karekodunu ürün sayfamızdan doğrulayabilirsiniz.', false],
    ['c', 'Teşekkürler, içim rahatladı.'],
]],
['topic' => 'Hamilelikte kullanım', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Hamileyim, retinol serumu kullanabilir miyim?'],
    ['a', 'Merhaba {ad} Hanım, hamilelik döneminde retinol kullanımı önerilmiyor. Bunun yerine C vitamini serumumuzu veya niasinamid serumumuzu tercih edebilirsiniz. Kesin bilgi için doktorunuza danışmanızı öneririz.', false],
    ['c', 'Çok teşekkür ederim, bilgilendirme için 🙏'],
]],
['topic' => 'Vegan & cruelty-free', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Ürünleriniz hayvanlar üzerinde test ediliyor mu?'],
    ['a', 'Hayır {ad} Hanım, hiçbir ürünümüz hayvanlar üzerinde test edilmiyor. Ürünlerimizin %90\'ı vegan sertifikalı; hangi ürünlerin vegan olduğunu ürün sayfasındaki rozetten görebilirsiniz.', true],
    ['c', 'Bunu duymak çok güzel 🌿'],
]],
['topic' => 'Son kullanma tarihi', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Gönderdiğiniz ürünlerin son kullanma tarihi ne kadar uzun oluyor?'],
    ['a', 'Merhaba {ad} Hanım, sevk ettiğimiz ürünlerin son kullanma tarihine en az 18 ay kalmış oluyor. Ambalaj üzerinde açıldıktan sonraki kullanım süresi (PAO) de belirtiliyor.', true],
    ['c', 'Teşekkürler.'],
]],
['topic' => 'Toptan & bayilik', 'status' => 'pending', 'urgent' => false, 'turns' => [
    ['c', 'Merhaba, güzellik salonum var. Toptan alım yapabilir miyim?'],
    ['a', 'Merhaba {ad} Hanım, kurumsal ve salon iş birlikleri için özel fiyat listemiz mevcut. Vergi levhanızı ve iletişim bilgilerinizi kurumsal@testfirma.demo adresine iletirseniz satış ekibimiz 1 iş günü içinde dönüş yapacak.', false],
]],
['topic' => 'İş birliği talebi', 'status' => 'pending', 'urgent' => false, 'turns' => [
    ['c', 'Selam! 18K takipçili bir cilt bakım hesabım var, iş birliği yapmak isterim.'],
    ['a', 'Merhaba {ad} Hanım, ilginiz için teşekkürler! İş birliği başvurularını isbirligi@testfirma.demo adresinden alıyoruz. Hesabınızın istatistiklerini (erişim, etkileşim oranı) ekleyerek yazarsanız pazarlama ekibimiz değerlendirip dönüş yapacak.', true],
]],
['topic' => 'Kullanım sırası', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Aldığım 4 ürünü hangi sırayla kullanmalıyım?'],
    ['a', 'Merhaba {ad} Hanım! Sabah: temizleme jeli → tonik → C vitamini serumu → nemlendirici → güneş kremi. Akşam: micellar su → temizleme jeli → tonik → retinol/niasinamid → nemlendirici. Serumlar inceden kalına doğru sürülür.', true],
    ['c', 'Kaydettim, çok yardımcı oldunuz 💛'],
]],
['topic' => 'Ürün karşılaştırma', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Ceramide krem ile Aqua Boost krem arasındaki fark ne?'],
    ['a', 'Merhaba {ad} Hanım, Aqua Boost hafif jel-krem dokusunda ve nem odaklı — yağlı/karma ciltler ve yaz ayları için ideal. Ceramide ise daha zengin dokulu ve bariyer onarımına odaklı — kuru, hassas ciltler ve kış ayları için daha uygun.', true],
    ['c', 'Kuru cildim var, Ceramide alayım o zaman.'],
]],
['topic' => 'Kargo ücreti', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Kargo ücretsiz mi?'],
    ['a', 'Merhaba {ad} Hanım, 750 TL ve üzeri siparişlerde kargo ücretsizdir. Altındaki siparişlerde 79,90 TL kargo bedeli uygulanıyor.', true],
    ['c', 'Tamam, biraz daha ekleyeyim sepete 😄'],
]],
['topic' => 'Üyelik & puan', 'status' => 'resolved', 'urgent' => false, 'turns' => [
    ['c', 'Puanlarımı nasıl kullanabilirim?'],
    ['a', 'Merhaba {ad} Hanım, hesabınızda 340 puan görünüyor (34 TL değerinde). Ödeme adımında "Puan kullan" kutusunu işaretleyerek sepet tutarından düşebilirsiniz.', true],
    ['c', 'Buldum, teşekkürler!'],
]],
['topic' => 'Şikâyet — koku', 'status' => 'closed', 'urgent' => false, 'turns' => [
    ['c', 'Aldığım vücut losyonunun kokusu eskisinden farklı, formülü mü değişti?'],
    ['a', 'Merhaba {ad} Hanım, geri bildiriminiz için teşekkürler. Formülde değişiklik yok ancak yeni partide doğal shea yağı oranını artırdık; bu da kokuyu bir miktar etkiliyor olabilir. Memnun kalmadıysanız ürünü iade alabiliriz.', false],
    ['c', 'Alışırım herhalde, sorun değil.'],
]],
['topic' => 'Teşekkür', 'status' => 'closed', 'urgent' => false, 'turns' => [
    ['c', 'Sadece teşekkür etmek için yazıyorum, C vitamini serumu cildimde inanılmaz fark yarattı! ✨'],
    ['a', 'Bunu duymak bizi çok mutlu etti {ad} Hanım! 🌸 Dilerseniz ürün sayfasında deneyiminizi paylaşabilirsiniz, diğer müşterilerimize çok yardımcı oluyor.', false],
]],
['topic' => 'Abonelik sorusu', 'status' => 'open', 'urgent' => false, 'turns' => [
    ['c', 'Her ay otomatik gönderim (abonelik) seçeneğiniz var mı?'],
]],
['topic' => 'Fiyat farkı', 'status' => 'open', 'urgent' => false, 'turns' => [
    ['c', 'Dün 599 TL olan serum bugün 649 TL olmuş, neden zam geldi?'],
]],
['topic' => 'Ürün önerisi — akne', 'status' => 'open', 'urgent' => true, 'turns' => [
    ['c', 'Akne izlerim var, hangi ürünleri kullanmalıyım? Acil cevap verebilir misiniz lütfen'],
]],
],

// ── Sosyal medya yorumları (Instagram / Facebook gönderileri) ───────────────
'comments' => [
    ['Bu serumu 2 aydır kullanıyorum, lekelerim gerçekten açıldı 😍', 'replied', 'Bunu duymak harika {ad}! 💛 Deneyiminizi paylaştığınız için teşekkürler.'],
    ['Fiyatı ne kadar acaba?', 'replied', 'Merhaba {ad}, 599,90 TL. Profilimizdeki linkten ürün sayfasına ulaşabilirsiniz 🌸'],
    ['Kargonuz kaç günde geliyor?', 'replied', 'Merhaba, siparişleriniz aynı gün kargoya veriliyor ve 1-3 iş gününde teslim ediliyor 📦'],
    ['Hassas ciltte kullanılır mı?', 'replied', 'Merhaba {ad}, evet — parfüm içermez ve dermatolojik test edilmiştir. İlk kullanımda kol içinde denemenizi öneririz 🌿'],
    ['Sipariş verdim hâlâ gelmedi 😡', 'replied', 'Merhaba {ad}, çok üzgünüz. DM\'den sipariş numaranızı iletirseniz hemen inceleyelim.'],
    ['Bu paleti çok beğendim, stokta var mı?', 'replied', 'Merhaba! Evet stoklarımızda mevcut 💕 Linkten sipariş verebilirsiniz.'],
    ['Vegan mı?', 'replied', 'Evet, bu ürünümüz vegan sertifikalıdır 🌱'],
    ['Erkekler de kullanabilir mi?', 'replied', 'Kesinlikle! Ürünlerimizin tamamı cinsiyetten bağımsız, cilt tipine göre seçiliyor 👌'],
    ['Renk kartelası paylaşır mısınız?', 'replied', 'Tabii ki, ürün sayfamızda tüm tonların üzerinde denenmiş görselleri var 🎨'],
    ['Fondöten 40 Sand tonu ne zaman gelir?', 'pending', null],
    ['Bunu hamileyken kullanabilir miyim?', 'pending', null],
    ['İade süreciniz nasıl işliyor?', 'pending', null],
    ['Yurt dışına gönderim yapıyor musunuz?', 'pending', null],
    ['Bu ürünü kuponla alabilir miyim?', 'pending', null],
    ['İçeriğinde alkol var mı?', 'pending', null],
    ['Setin içinde tam olarak hangi ürünler var?', 'pending', null],
    ['Bayilik veriyor musunuz?', 'pending', null],
    ['Ne zaman indirime girer? 🥲', 'pending', null],
    ['Gece mi gündüz mü kullanılıyor?', 'pending', null],
    ['Bu kokuyu bayıldım, tekrar alacağım kesin ❤️', 'pending', null],
],

'posts' => [
    ['C vitamini serumumuzla 4 haftalık dönüşüm ✨ #cildinizinhikayesi', 'https://www.instagram.com/p/DEMO001/'],
    ['Yeni gelen Nude Dreams far paleti şimdi stoklarda 🤎', 'https://www.instagram.com/p/DEMO002/'],
    ['Kış bakımı: bariyerinizi onarın 🧴 Ceramide krem hakkında bilmeniz gerekenler', 'https://www.instagram.com/p/DEMO003/'],
    ['3 adımda sabah rutini — kaydet, unutma 📌', 'https://www.instagram.com/p/DEMO004/'],
    ['Anneler Günü hediye setleri hazır 🎁', 'https://www.facebook.com/testfirma/posts/DEMO005'],
    ['SPF her mevsim! Yeni renksiz güneş kremimiz raflarda ☀️', 'https://www.instagram.com/p/DEMO006/'],
],

// ── Ürün yorumları (site + pazaryeri) ──────────────────────────────────────
'reviews' => [
    [5, 'Gerçekten işe yarıyor. 3 haftada cilt tonum belirgin şekilde eşitlendi, kesinlikle tavsiye ederim.'],
    [5, 'Kokusu çok hafif, yapışkan hissi bırakmıyor. Sabah makyaj altında da sorun çıkarmadı.'],
    [5, 'Fiyat performans olarak muhteşem. Yurt dışı markalardan hiç aşağı kalmıyor.'],
    [5, 'Kargo çok hızlı geldi, paketleme özenliydi. Ürün beklentimin üzerinde çıktı.'],
    [4, 'Ürün güzel ama şişesi biraz küçük geldi, 50 ml seçeneği de olsa alırdım.'],
    [4, 'Memnunum ancak damlalık bazen fazla ürün alıyor.'],
    [4, 'İyi bir nemlendirici, kuru cildim için kışın biraz daha yoğunu gerekiyor.'],
    [4, 'Etkisini görmek 1 ayı buldu ama sonuçtan memnunum.'],
    [3, 'Fena değil, beklediğim kadar etkili olmadı. Belki daha uzun kullanmak gerekiyor.'],
    [3, 'Ürün ortalama, ambalajı daha iyi olabilirdi.'],
    [2, 'Bana uymadı, cildimde hafif kızarıklık yaptı. İade ettim, süreç sorunsuzdu.'],
    [5, 'İkinci kez alıyorum, artık rutinimin vazgeçilmezi 💛'],
    [5, 'Hediye olarak aldım, çok beğenildi. Kutusu da şık.'],
    [4, 'Kalıcılığı iyi ama gün sonunda hafif soluyor.'],
    [5, 'Hassas cildim var, hiçbir tahriş yapmadı. Çok memnunum.'],
    [5, 'Dokusu çok hafif, yaz için birebir.'],
    [4, 'Güzel ürün, tek eksik kokusunun biraz belirgin olması.'],
    [5, 'Eşim için aldım, o da çok beğendi. Tekrar sipariş vereceğiz.'],
    [3, 'Ürün iyi ama kargo 5 gün sürdü.'],
    [5, 'Uzun zamandır aradığım ürünmüş. Gözeneklerim gözle görülür şekilde küçüldü.'],
],

'review_replies' => [
    'Değerli yorumunuz için teşekkür ederiz 💛 Sağlıklı günler dileriz!',
    'Geri bildiriminiz için teşekkürler, önerinizi ürün ekibimize ilettik.',
    'Yaşadığınız olumsuzluk için üzgünüz. Müşteri hizmetlerimize yazarsanız çözüm için elimizden geleni yaparız.',
],

// ── Bilgi bankası ──────────────────────────────────────────────────────────
'knowledge' => [
    ['Kargo & Teslimat', 'Kargo süresi ve ücretleri', "Siparişler hafta içi saat 16:00'ya kadar verilirse aynı gün kargoya verilir. Teslimat süresi 1-3 iş günüdür.\n\n• 750 TL ve üzeri siparişlerde kargo ÜCRETSİZ\n• 750 TL altı siparişlerde 79,90 TL kargo bedeli\n• Kapıda ödeme hizmet bedeli: 49,90 TL\n\nAnlaşmalı kargo firmaları: Sürat, Yurtiçi, Aras.", ['kargo', 'teslimat', 'ücret']],
    ['İade & Değişim', 'İade koşulları ve süresi', "Ürünlerinizi teslim tarihinden itibaren 30 gün içinde iade edebilirsiniz.\n\nKoşullar:\n• Ürün kullanılmamış ve orijinal ambalajında olmalı\n• Hijyen gereği açılmış kozmetik ürünler iade alınamaz\n• Hasarlı/yanlış ürün gönderiminde ambalaj şartı aranmaz\n\nİade kargo kodu SMS ile gönderilir, kargo ücreti firmamıza aittir. İade tutarı depoya ulaşımdan sonra 2-7 iş günü içinde ödeme yönteminize iade edilir.", ['iade', 'değişim', 'süre']],
    ['Ödeme', 'Ödeme yöntemleri ve taksit', "Kabul edilen ödeme yöntemleri: kredi/banka kartı, havale/EFT, kapıda ödeme (nakit veya kart).\n\nTaksit:\n• 750 TL üzeri → 3 taksit\n• 1.500 TL üzeri → 6 taksit\n\nTüm ödemeler 3D Secure ile korunur.", ['ödeme', 'taksit', 'kapıda ödeme']],
    ['Ürün Bilgisi', 'Cilt tipine göre ürün seçimi', "KURU CİLT: Ceramide Bariyer Onarıcı Krem, HA Serum, Gece Yenileyici Krem\nYAĞLI/KARMA: Niasinamid Serum, Matlaştırıcı Jel Krem, AHA-BHA Temizleyici\nHASSAS: Ceramide Krem, Gül Suyu Tonik, Enzim Peeling\nOLGUN: Retinol Serum, Peptit Serum, Kolajen Kremi\nLEKELİ: C+ Vitamin C Serumu, Glow Essence, SPF 50+", ['cilt tipi', 'öneri', 'rutin']],
    ['Ürün Bilgisi', 'Aktif içerik kullanım kuralları', "• Retinol yalnızca GECE kullanılır, gündüz SPF şarttır.\n• Retinol ve AHA/BHA aynı akşam kullanılmaz.\n• Vitamin C sabah, retinol gece önerilir.\n• Niasinamid her iki zamanda da kullanılabilir.\n• Hamilelik ve emzirme döneminde retinol önerilmez.\n• Yeni aktife haftada 2 geceyle başlanır, kademeli artırılır.", ['retinol', 'aktif', 'kullanım']],
    ['Genel', 'Vegan ve cruelty-free politikası', "Hiçbir ürünümüz hayvanlar üzerinde test edilmemektedir. Ürünlerimizin %90'ı vegan sertifikalıdır; vegan olmayan ürünlerimizde arı ürünleri (bal mumu, propolis) bulunabilir. Vegan rozeti ürün sayfalarında gösterilir.", ['vegan', 'cruelty-free', 'sertifika']],
    ['Genel', 'Son kullanma tarihi ve saklama', "Sevk edilen ürünlerin son kullanma tarihine en az 18 ay vardır. Ambalaj üzerindeki PAO simgesi (örn. 12M) açıldıktan sonraki kullanım süresini gösterir. Ürünleri doğrudan güneş ışığından uzakta, oda sıcaklığında saklayın.", ['skt', 'saklama', 'pao']],
    ['Sipariş İşlemleri', 'Sipariş iptali ve adres değişikliği', "Kargoya verilmemiş siparişlerde iptal ve adres değişikliği yapılabilir. Talebinizi müşteri hizmetlerine iletmeniz yeterlidir. Kargoya verilmiş siparişlerde iptal yapılamaz; teslim aldıktan sonra iade süreci işletilir.", ['iptal', 'adres', 'sipariş']],
],

'quick_replies' => [
    ['Kargo & Teslimat', 'Kargo süresi', 'Siparişiniz aynı gün kargoya verilir, 1-3 iş günü içinde adresinize teslim edilir. 750 TL üzeri siparişlerde kargo ücretsizdir 📦'],
    ['Kargo & Teslimat', 'Kargo takip', 'Merhaba, siparişiniz {kargo} ile yola çıktı. Takip numaranız: {takip}'],
    ['Kargo & Teslimat', 'Kargo ücreti', '750 TL ve üzeri siparişlerde kargo ücretsizdir. Altındaki siparişlerde 79,90 TL kargo bedeli uygulanır.'],
    ['İade & Değişim', 'İade süresi', 'Ürünlerinizi teslim tarihinden itibaren 30 gün içinde iade edebilirsiniz. Hijyen gereği açılmış kozmetik ürünler iade alınamaz.'],
    ['İade & Değişim', 'İade nasıl yapılır', 'İade talebinizi sitemizin İade sayfasından sipariş numaranızla oluşturabilirsiniz. Kargo kodunuzu SMS ile gönderiyoruz, kargo ücreti bize aittir.'],
    ['İade & Değişim', 'İade parası', 'İadeniz depomuza ulaştıktan sonra tutar 2-7 iş günü içinde ödeme yaptığınız karta iade edilir.'],
    ['Ödeme', 'Taksit seçenekleri', '750 TL üzeri siparişlerde 3 taksit, 1.500 TL üzeri siparişlerde 6 taksit imkânı sunuyoruz.'],
    ['Ödeme', 'Kapıda ödeme', 'Kapıda nakit veya kredi kartıyla ödeme yapabilirsiniz. Kapıda ödeme hizmet bedeli 49,90 TL\'dir.'],
    ['Stok & Ürün', 'Stokta yok', 'Bu ürünümüz şu anda stoklarımızda bulunmuyor. Ürün sayfasındaki "Gelince Haber Ver" butonuna e-postanızı bırakırsanız stoğa girer girmez size haber veriyoruz 🔔'],
    ['Stok & Ürün', 'Vegan & cruelty-free', 'Hiçbir ürünümüz hayvanlar üzerinde test edilmiyor. Ürünlerimizin %90\'ı vegan sertifikalıdır 🌱'],
    ['Stok & Ürün', 'Orijinallik', 'Tüm ürünlerimiz kendi markamız Luméa\'ya aittir ve üreticiden doğrudan tedarik edilir. Karekod ile doğrulayabilirsiniz.'],
    ['Ürün Danışmanlığı', 'Cilt tipi sorusu', 'Size en uygun ürünü önerebilmemiz için cilt tipinizi (kuru / yağlı / karma / hassas) ve öncelikli sorununuzu (leke, kuruluk, gözenek, ince çizgi) öğrenebilir miyim? 🌸'],
    ['Ürün Danışmanlığı', 'Retinol uyarısı', 'Retinol yalnızca gece kullanılır ve gündüz mutlaka SPF ile desteklenmelidir. Hamilelik ve emzirme döneminde önerilmez.'],
    ['Ürün Danışmanlığı', 'Rutin sırası', 'Sabah: temizleyici → tonik → serum → nemlendirici → güneş kremi.\nAkşam: makyaj temizleyici → temizleyici → tonik → aktif serum → nemlendirici.'],
    ['Genel', 'Karşılama', 'Merhaba, Test Firma\'ya hoş geldiniz! 🌸 Size nasıl yardımcı olabilirim?'],
    ['Genel', 'Kapanış', 'Başka bir konuda yardımcı olabileceğim bir şey var mı? İyi günler dileriz 💛'],
    ['Genel', 'Bekletme', 'Bir dakikanızı rica edeceğim, kaydınızı kontrol ediyorum 🙏'],
    ['Genel', 'Özür & telafi', 'Yaşadığınız olumsuzluk için çok üzgünüz. Telafi olarak bir sonraki siparişinizde geçerli indirim kodunuzu tanımladım.'],
    ['İş Birliği', 'İş birliği yönlendirme', 'İş birliği başvurularınızı hesap istatistiklerinizle birlikte isbirligi@testfirma.demo adresine iletebilirsiniz 💌'],
    ['İş Birliği', 'Toptan & bayilik', 'Kurumsal ve salon iş birlikleri için kurumsal@testfirma.demo adresine vergi levhanızla yazabilirsiniz.'],
],

// ── Arama motoru sorguları ─────────────────────────────────────────────────
'searches' => [
    ['vitamin c serum', 24, 'product'], ['retinol', 18, 'ingredient'], ['nemlendirici', 31, 'category'],
    ['hyaluronik asit', 16, 'ingredient'], ['güneş kremi', 12, 'category'], ['spf 50', 9, 'attribute'],
    ['fondöten', 14, 'category'], ['ruj', 22, 'category'], ['mat ruj', 11, 'attribute'],
    ['maskara', 8, 'category'], ['far paleti', 6, 'category'], ['kuru cilt', 27, 'need'],
    ['yağlı cilt için serum', 19, 'need'], ['akne', 15, 'need'], ['leke kremi', 21, 'need'],
    ['göz altı morluk', 7, 'need'], ['niasinamid', 13, 'ingredient'], ['şampuan', 17, 'category'],
    ['saç dökülmesi', 10, 'need'], ['saç maskesi', 8, 'category'], ['parfüm', 12, 'category'],
    ['kadın parfüm', 6, 'category'], ['el kremi', 9, 'category'], ['duş jeli', 11, 'category'],
    ['hediye seti', 5, 'category'], ['vücut losyonu', 8, 'category'], ['peeling', 9, 'category'],
    ['kil maskesi', 7, 'category'], ['tonik', 10, 'category'], ['temizleme jeli', 13, 'category'],
    ['micellar su', 6, 'product'], ['sakal yağı', 4, 'category'], ['oje', 8, 'category'],
    ['hassas cilt', 14, 'need'], ['vegan kozmetik', 5, 'attribute'], ['indirimli ürünler', 18, 'promo'],
    ['c vitamini serum', 11, 'product'], ['lumea serum', 9, 'brand'], ['kolajen', 12, 'ingredient'],
    ['gözenek sıkılaştırıcı', 8, 'need'], ['dudak balmı', 7, 'category'], ['allık', 6, 'category'],
],
'searches_zero' => ['bb krem', 'saç boyası', 'kirpik serumu', 'tırnak güçlendirici', 'diş beyazlatıcı', 'saç düzleştirici', 'epilasyon', 'kolonya'],

// ── Chatbot diyalogları ────────────────────────────────────────────────────
'chats' => [
    [['u', 'kargom nerede'], ['a', 'Sipariş numaranızı paylaşabilir misiniz? Kargo durumunuzu hemen kontrol edeyim 📦'], ['u', '{siparis}'], ['a', 'Siparişiniz {kargo} ile yolda, takip numarası {takip}. Tahmini teslim: 1-2 iş günü.']],
    [['u', 'kuru cildim için ne önerirsin'], ['a', 'Kuru ciltler için Ceramide Bariyer Onarıcı Krem ve HA Yoğun Nemlendirici Serum ikilisini öneriyorum. İkisini birlikte kullanmak nem kaybını belirgin şekilde azaltıyor 🌿'], ['u', 'fiyatları ne kadar'], ['a', 'Ceramide Krem 679,90 TL, HA Serum 519,90 TL. İkisini birlikte alırsanız sepette kargo ücretsiz oluyor.']],
    [['u', 'iade nasıl yapılır'], ['a', 'Teslim tarihinden itibaren 30 gün içinde iade edebilirsiniz. İade sayfamızdan sipariş numaranızla talep oluşturmanız yeterli — kargo kodunu SMS ile gönderiyoruz, kargo ücreti bize ait 🚚']],
    [['u', 'retinol kullanabilir miyim hamileyim'], ['a', 'Hamilelik döneminde retinol kullanımı önerilmiyor. Bunun yerine C vitamini veya niasinamid serumumuzu tercih edebilirsiniz. Kesin bilgi için doktorunuza danışmanızı öneririz 🙏']],
    [['u', 'indirim kodu var mı'], ['a', 'Şu anda 750 TL üzeri siparişlerde kargo ücretsiz. Ayrıca çarkı çevirerek size özel indirim kodu kazanabilirsiniz 🎡']],
    [['u', 'siparişimi iptal etmek istiyorum'], ['a', 'Sipariş numaranızı ve iptal nedeninizi paylaşabilir misiniz? Kargoya verilmemişse hemen iptal edebiliyoruz.'], ['u', '{siparis} yanlışlıkla iki kez sipariş verdim'], ['a', 'Anladım, talebinizi müşteri temsilcimize aktarıyorum. En kısa sürede size dönüş yapılacak.']],
    [['u', 'fondöten tonu nasıl seçerim'], ['a', 'Doğal ışıkta çene hattınıza iki farklı tonu sürüp bekleyin — cildinizde kaybolan ton doğru olandır. Açık ten + sarı alt ton için 20 Ivory, orta ten için 30 Beige öneriyoruz 💄']],
    [['u', 'stokta yok yazıyor ne zaman gelir'], ['a', 'Bu ürün için yeni parti yolda, yaklaşık 10 gün içinde stoklarımızda olacak. Ürün sayfasındaki "Gelince Haber Ver" butonuna e-postanızı bırakırsanız haber veriyoruz 🔔']],
    [['u', 'vegan mı ürünleriniz'], ['a', 'Ürünlerimizin %90\'ı vegan sertifikalı ve hiçbiri hayvanlar üzerinde test edilmiyor 🌱 Vegan rozetini ürün sayfasında görebilirsiniz.']],
    [['u', 'kapıda ödeme var mı'], ['a', 'Evet, kapıda nakit veya kredi kartıyla ödeme yapabilirsiniz. Kapıda ödeme hizmet bedeli 49,90 TL\'dir 💳']],
],

// ── Çağrı özetleri ─────────────────────────────────────────────────────────
'calls' => [
    ['Kargo takibi', 'Müşteri siparişinin kargo durumunu sordu; takip numarası paylaşıldı ve 1-2 iş günü içinde teslim edileceği bilgisi verildi.', 'positive'],
    ['İade talebi', 'Müşteri fondöten tonunun uymadığını belirtti; iade koşulları anlatıldı ve iade kargo kodu SMS ile gönderildi.', 'neutral'],
    ['Ürün danışmanlığı', 'Karma cilt için ürün önerisi istendi; niasinamid serum ve matlaştırıcı krem önerildi, müşteri sipariş verdi.', 'positive'],
    ['Şikâyet — kargo gecikmesi', 'Müşteri 6 gündür teslim alamadığını belirterek şikâyette bulundu; kargo firmasıyla görüşüleceği ve indirim kodu tanımlanacağı bildirildi.', 'negative'],
    ['Sipariş iptali', 'Mükerrer sipariş nedeniyle iptal talebi alındı; sipariş kargoya verilmediği için iptal edildi.', 'neutral'],
    ['Fatura talebi', 'E-arşiv fatura talep edildi; müşterinin kayıtlı e-posta adresine iletildi.', 'neutral'],
    ['Hasarlı ürün', 'Serum şişesinin kırık geldiği bildirildi; ücretsiz yenisi gönderilecek şekilde kayıt açıldı.', 'negative'],
    ['Kampanya bilgisi', 'Müşteri güncel kampanyaları sordu; kargo bedava eşiği ve set indirimleri anlatıldı.', 'positive'],
    ['Stok sorgusu', 'Stokta olmayan allık için tedarik tarihi soruldu; gelince haber ver kaydı oluşturuldu.', 'neutral'],
    ['Toptan alım', 'Güzellik salonu sahibi toptan fiyat listesi istedi; kurumsal satış ekibine yönlendirildi.', 'positive'],
],

// ── İade nedenleri ─────────────────────────────────────────────────────────
'return_reasons' => [
    ['wrong_color', 'Ton/renk beklediğim gibi değil'],
    ['not_suitable', 'Cildime uymadı'],
    ['changed_mind', 'Vazgeçtim'],
    ['damaged', 'Ürün hasarlı geldi'],
    ['wrong_item', 'Yanlış ürün gönderildi'],
    ['expired', 'Son kullanma tarihi yakın'],
    ['allergic', 'Alerjik reaksiyon oluştu'],
    ['late_delivery', 'Geç teslimat'],
],

// ── Pazarlama ──────────────────────────────────────────────────────────────
'campaigns' => [
    ['Kış Bakım Kampanyası', 'email', 'Kışın cildiniz susuz kalmasın 🧴 %25\'e varan indirim', 'completed'],
    ['Sepette Kalanlar — Hatırlatma', 'whatsapp', null, 'completed'],
    ['Yeni Sezon Far Paleti Lansmanı', 'email', 'Nude Dreams sizinle tanışmak istiyor 🤎', 'completed'],
    ['Anneler Günü Hediye Setleri', 'email', 'Ona en güzel hediyeyi seçin 🎁 Setlerde %30 indirim', 'completed'],
    ['VIP Müşteri Özel İndirimi', 'whatsapp', null, 'completed'],
    ['Güneş Kremi Sezon Açılışı', 'email', 'SPF her mevsim ☀️ Güneş bakımında %20 indirim', 'paused'],
],
'segments' => [
    ['VIP Müşteriler', 'Son 12 ayda 5+ sipariş veya 10.000 TL+ harcama yapanlar'],
    ['Yeni Müşteriler', 'İlk siparişini son 30 gün içinde vermiş müşteriler'],
    ['Uykuda Müşteriler', '90-365 gündür sipariş vermeyen, en az 1 siparişi olanlar'],
    ['Cilt Bakımı Meraklıları', 'Yalnızca cilt bakımı kategorisinden alışveriş yapanlar'],
    ['Makyaj Müşterileri', 'Son 6 ayda makyaj kategorisinden alışveriş yapanlar'],
    ['Sepette Bırakanlar', 'Son 14 günde sepet oluşturup satın almayanlar'],
    ['Tek Siparişliler', 'Yalnızca 1 sipariş vermiş, tekrar alışverişe dönmemiş müşteriler'],
    ['Yüksek Sepetliler', 'Ortalama sipariş tutarı 1.500 TL üzeri olanlar'],
],

// ── Sosyal medya planı ─────────────────────────────────────────────────────
'social' => [
    ['Sabah rutini 3 adımda ☀️ Kaydet, unutma!', ['instagram'], 'published'],
    ['Retinol kullanırken yapılan 5 hata 🚫 #cildbakımı', ['instagram', 'facebook'], 'published'],
    ['Yeni: Nude Dreams 12\'li far paleti 🤎 Şimdi stoklarda', ['instagram'], 'published'],
    ['Kuru cilt mi karma cilt mi? 60 saniyede öğrenin 🧴', ['instagram', 'tiktok'], 'published'],
    ['Hafta sonu kampanyası: setlerde %30 indirim 🎁', ['instagram', 'facebook'], 'scheduled'],
    ['Müşterimizin 8 haftalık C vitamini deneyimi ✨', ['instagram'], 'scheduled'],
    ['SPF neden her mevsim gerekli? ☀️', ['instagram', 'facebook'], 'draft'],
    ['Yeni gelen parfümlerimizle tanışın 🖤', ['instagram'], 'draft'],
],

// ── Meta reklam stratejileri ───────────────────────────────────────────────
'strategies' => [
    ['catalog_scale', 'Katalog Satış Ölçeklendirme', 'scaling', 'active',
     'En çok dönüşüm getiren cilt bakımı setlerini Advantage+ katalog kampanyasında ölçekle.',
     'Son 30 günde katalog kampanyasının ROAS değeri 4,2 ile hesap ortalamasının %38 üzerinde. Günlük bütçe kademeli olarak 750 TL\'den 1.500 TL\'ye çıkarılmalı.'],
    ['cart_retargeting', 'Sepet Terk Yeniden Pazarlama', 'retargeting', 'active',
     'Son 14 günde sepete ekleyip satın almayan kitleye dinamik ürün reklamı göster.',
     'Sepet terk oranı %68. Bu kitleye özel %10 indirim kodlu DPA kreatifi ile dönüşüm maliyetinin %35 düşmesi bekleniyor.'],
    ['ugc_creative', 'UGC Kreatif Testi', 'creative', 'active',
     'Gerçek müşteri videolarını 3 farklı açılış kancasıyla test et.',
     'Stüdyo çekimi kreatiflerde 21. günde tıklama oranı %40 düştü — kreatif yorgunluğu. UGC formatı benzer hesaplarda %25 daha uzun ömür gösteriyor.'],
    ['new_customer', 'Yeni Müşteri Kazanımı', 'prospecting', 'draft',
     'Benzer hedef kitle (%1) üzerinden ilk satın alma odaklı kampanya kur.',
     'Mevcut kitlelerde frekans 3,8\'e ulaştı. Yeni kitle genişlemesi olmadan maliyetler artmaya devam edecek.'],
    ['winback', 'Uykudaki Müşteri Geri Kazanımı', 'retention', 'paused',
     '90-365 gün arası sipariş vermeyen müşterilere özel teklif göster.',
     'Bu segmentte 1.240 müşteri var. Geçmiş kampanyada geri dönüş oranı %8,4 ile ortalamanın üzerindeydi.'],
],
'ad_recommendations' => [
    ['budget_increase', 'high', 'Katalog kampanyasının bütçesini artırın', 'ROAS 4,2 ile hedefin %38 üzerinde ve bütçe gün içinde tükeniyor. Günlük bütçeyi %20 artırmak ek dönüşüm getirecektir.', 'increase_budget'],
    ['creative_fatigue', 'high', 'Kreatif yorgunluğu: "Kış Bakımı" reklam seti', 'Frekans 4,1\'e ulaştı ve TO son 7 günde %42 düştü. Kreatifi yenilemek veya kitleyi genişletmek gerekiyor.', 'refresh_creative'],
    ['low_performer', 'medium', 'Düşük performanslı reklamı durdurun', 'Bu reklam 30 günde 2.840 TL harcadı ve 1 dönüşüm getirdi. Hesap ortalamasının 6 katı maliyet.', 'pause_ad'],
    ['audience_overlap', 'medium', 'Kitle çakışması tespit edildi', 'İki reklam setinin kitleleri %47 örtüşüyor; birbirlerinin maliyetini artırıyorlar. Setleri birleştirmeniz önerilir.', 'merge_adsets'],
    ['placement', 'low', 'Reels yerleşimi öne çıkıyor', 'Reels yerleşiminde edinme maliyeti feed\'e göre %31 daha düşük. Bütçenin daha büyük kısmını Reels\'e ayırın.', 'adjust_placement'],
],
];
