<?php

namespace App\Services\Chat;

/**
 * ÇOK SORULAN SORULAR — sohbetin en üstündeki çipler ve arkalarındaki
 * DETERMİNİSTİK cevaplar. Bkz docs/ai-chatbot.md.
 *
 * Neden var (5 Eyl): çipler LLM'e serbest soru olarak gidiyordu ve en çok
 * sorulan üç soruda bile sonuç kumardı —
 *   • "Ürün önerisi istiyorum" → boş arama → "aradığınız ürünü bulamadım"
 *   • "İade nasıl yapılır?"    → süreci anlatan kayıt dönüyordu, oysa müşterinin
 *                                ihtiyacı iade talebini açtığı LİNK
 *   • "Siparişim nerede?"      → modelin need_order_info demesine bağlıydı
 *
 * Buradaki niyetler hem çipe tıklayanı hem AYNI SORUYU KENDİ CÜMLESİYLE yazanı
 * yakalar. Cevap iki yoldan gelir:
 *   action=faq      → mağazanın KENDİ onaylı kaydı BİREBİR gönderilir
 *                     (ChatKnowledge::best; sıfır uydurma, sıfır LLM maliyeti,
 *                      metnin sahibi müşteri hizmetleri — panelden günceller)
 *   action=products/order → ChatbotService'in mevcut akışı devralır
 *
 * Mağazaya özel HİÇBİR metin burada gömülü DEĞİLDİR: her tenant kendi
 * quick_replies/knowledge_base kayıtlarından cevaplanır. Kayıt yoksa niyet
 * sessizce düşer ve normal akış (LLM + kapılar + devir) çalışır.
 */
class ChatIntents
{
    /**
     * Sıralama ÖNEMLİ: daha özel niyet önce gelir ("iade param ne zaman"
     * → iade_para, "iade nasıl" → iade_nasil).
     *
     * chip    : varsayılan çip metni (panelden chat_quick_replies ile değişir)
     * match   : müşterinin kendi yazımını da yakalayan kalıp
     * action  : faq | products | order
     * query   : faq'te onaylı kaydı bulmak için MAĞAZA DİLİYLE sabit sorgu
     *           (müşterinin cümlesi değil — kayıt başlıkları personel dilinde)
     * anchor  : kaydın konuya ait olduğunun kanıtı (bkz ChatKnowledge::topDocs);
     *           skor tek başına yetmez — küçük korpuslu tenant'ta alakasız kayıt
     *           en yüksek skoru alabiliyor
     * require_url : cevabın LİNK içermesi şart (iade talebi açma gibi konularda
     *           müşterinin ihtiyacı linktir; mağazada linkli kayıt yoksa hazır
     *           cevap verilmez, normal akış çalışır)
     * anchor_content : aynı şart, başlık ya da içerikte aranır
     * prefer_url : yakın skorlu kayıtlar arasında link içereni seç
     * min     : bu skorun altında kayıt "yeterince ilgili" sayılmaz
     */
    public const CATALOG = [
        'urun_oneri' => [
            'chip' => 'Ürün önerisi istiyorum',
            'action' => 'products',
            'match' => '/(urun|model|ayakkab|bot|sandalet|terlik)\w*\s*(oneri|tavsiye)|(oneri|tavsiye)\w*\s*(istiyorum|ister|alabilir|var\s*mi|verir)|ne\s*(onerir|tavsiye)|bana\s*(bir\s*)?(sey|urun|model|ayakkabi)\s*(oner|bul|goster)|(oner|tavsiye\s*et)\w*\s*mi(siniz|sin)/u',
        ],
        'hesabim' => [
            'chip' => 'Siparişlerimi nereden görürüm?',
            'action' => 'faq',
            'query' => 'siparişlerim sayfası üye girişi sipariş takip',
            'require_url' => true,
            'min' => 0.28,
            'anchor' => [['siparis', 'hesab', 'uye']],
            'match' => '/(siparis\w*|hesab\w*|uyelik)\w*\s*(sayfasi\w*)?\s*(nereden|nerden|nasil)\s*(gir|bak|gor|ulas|takip)|siparis\w*\s*(nereden|nerden)\s*(gorur|takip)|hesabim\w*\s*(nasil|nereden)|siparis\s*takip\s*(sayfasi|linki|nereden)/u',
        ],
        'siparis_durum' => [
            'chip' => 'Siparişim nerede?',
            'action' => 'order',
            'match' => '/(siparis|kargo|paket|urun)\w*\s*(nerede|nerde|ne\s*durumda|durumu\b|takip)|siparis\w*\s*(sorgula|kontrol|durum)|kargo\w*\s*(nerede|nerde|cikti\s*mi|verildi\s*mi)|takip\s*(numaram|kodum|no)|nerede\s*(siparis|kargo)/u',
        ],
        'iade_kargo' => [
            'chip' => 'İade kargosu ücretli mi?',
            'action' => 'faq',
            'query' => 'iade kargo ücreti ücretsiz iade kodu',
            'min' => 0.30,
            'anchor' => [['iade'], ['kargo']],
            // ⚠ "kargo" kelimesi ZORUNLU: onsuz "iade param ne zaman yatar" da
            // buraya düşüyordu (iade_para'nın işi).
            'match' => '/iade\s*kargo\w*\s*(ucret|bedel|para|bedava|ucretsiz|kim|nasil|kac)|iade\s*(icin\s*)?(ucretsiz|bedava)\s*kargo|(ucretsiz|bedava)\s*iade\s*kargo|iade\s*kargo\w*\s*(var|yok)\s*mu/u',
        ],
        'iade_para' => [
            'chip' => 'İade param ne zaman yatar?',
            'action' => 'faq',
            'query' => 'iade ödeme para iadesi kaç iş günü hesaba aktarılır',
            'min' => 0.30,
            'anchor' => [['iade'], ['odeme', 'para', 'ucret', 'tutar', 'hesab', 'kart', 'gun']],
            // ⚠ `ucret(?!siz)`: "iade ücretsiz mi" PARA İADESİ sorusu DEĞİLDİR —
            // müşteri iade kargo bedelini soruyor. Lookahead olmadan bot
            // "ödemeniz 2-5 iş günü içinde kartınıza aktarılır" diye cevaplıyordu.
            'match' => '/(iade|geri)\s*(odeme|param|ucret(?!siz)|tutar)\w*|param\w*\s*ne\s*zaman|(odeme|para|ucret(?!siz)|tutar)\w*\s*ne\s*zaman\s*(yat|gel|aktar|gec|ode)|(iade|odeme)\w*\s*(kac\s*gun|ne\s*zaman)/u',
        ],
        'iade_suresi' => [
            'chip' => 'İade süresi kaç gün?',
            'action' => 'faq',
            'query' => 'kolay iade süresi teslim tarihinden itibaren gün',
            'min' => 0.28,
            'anchor' => [['iade']],
            'anchor_content' => [['gun', 'sure', 'tarih', 'hafta']],
            'match' => '/iade\w*\s*(suresi|sure|kac\s*gun|gun|hakki)|kac\s*gun\w*\s*(iade|icinde\s*iade)|iade\s*(icin\s*)?(son|sure)/u',
        ],
        'iade_nasil' => [
            'chip' => 'İade / iptal nasıl yapılır?',
            'action' => 'faq',
            'query' => 'iade iptal talebi link üzerinden nasıl oluşturulur',
            'require_url' => true,
            'min' => 0.28,
            'anchor' => [['iade', 'iptal']],
            'match' => '/(iade|iptal)\w*\s*(nasil|nereden|nasi)|nasil\s*(iade|iptal)|iade\s*(etmek|edecegim|yapmak|talebi|olustur|kodu|linki?)|siparis\w*\s*(iade|iptal)\s*(etmek|edebilir|nasil|istiyorum)?|iptal\s*(etmek|edebilir|talebi|istiyorum)|(iade|iptal)\s*(islemi|sureci)\s*(nasil|nereden)/u',
        ],
        'degisim' => [
            'chip' => 'Değişim yapabilir miyim?',
            'action' => 'faq',
            'query' => 'değişim var mı ürün değiştirme iade',
            'min' => 0.30,
            'anchor' => [['degis']],
            'match' => '/degis(im|tir|ebilir|ecek)|beden\w*\s*degis|numara\w*\s*degis|urun\w*\s*degis/u',
        ],
        'stok_haber' => [
            'chip' => 'Bedenim tükenmiş, gelecek mi?',
            'action' => 'faq',
            'query' => 'stok yenilenecek mi gelince haber ver tükendi',
            'min' => 0.30,
            'anchor' => [['stok', 'tukend', 'yenilen']],
            'match' => '/(stok|beden|numara|renk)\w*\s*\w*\s*(tukenm|tukend|bitmis|gelecek\s*mi|yenilen|girecek|gelir\s*mi)|tukenm|tukend|gelince\s*haber|tekrar\s*(gelecek|stok)|stok\w*\s*(ne\s*zaman|var\s*mi|girer|yenilen)/u',
        ],
        'kargo_sure' => [
            'chip' => 'Kargom ne zaman gelir?',
            'action' => 'faq',
            'query' => 'kargo süreleri kaç iş günü içinde teslim',
            'min' => 0.30,
            'anchor' => [['kargo', 'teslim'], ['gun', 'sure', 'termin', 'hafta', 'saat']],
            // Cevabın BAŞINA canlı kesim saati cümlesi eklenir (bkz ChatShipping):
            // ürün detaydaki "bugün kargoda" rozetiyle aynı kaynak, aynı söz.
            'live' => 'shipping',
            // 8 Eyl: "kaç güne gönderirsiniz", "bugün sipariş versem ne zaman elime
            // ulaşır", "siparişim ne zaman kargolanır" kalıp dışında kalıp boşuna
            // devrediliyordu (sohbet #243/#247/#309/#722).
            'match' => '/kargo\w*\s*(ne\s*zaman|kac|sure|hangi\s*firma|firmasi|ile|cikis|verilir|verilecek|yola)|kac\s*gun\w*\s*(gelir|ulasir|teslim|elimde|gonder|kargo)|teslim\w*\s*(sure|ne\s*zaman|tarihi)|ne\s*zaman\s*(gelir|ulasir|kargoya|kargola|elime|elimde|gonder)|termin|siparis\w*\s*ne\s*zaman\s*(kargo|gonder|cik)|(bugun|simdi)\s*siparis\s*ver\w*\s*ne\s*zaman|ayni\s*gun\s*kargo/u',
        ],
        // 8 Eyl: sohbetlerde tekrar tekrar temsilciye devredilen ama cevabı
        // mağazanın kendi kayıtlarında DURAN iki konu (#313 kargo ücreti,
        // #300/#315 indirim-hediye çeki kodu, #707 "siparişlerime nereden girerim").
        'kargo_ucreti' => [
            'chip' => 'Kargo ücreti ne kadar?',
            'action' => 'faq',
            'query' => 'kargo ücreti ücretsiz kargo sepet tutarı',
            'min' => 0.30,
            'anchor' => [['kargo'], ['ucret', 'bedel', 'para', 'tl', 'ucretsiz']],
            // ⚠ İADE kargosu ayrı kayıt: "iade kargo ücretsiz mi" bu niyete DÜŞMEZ
            // (aşağıdaki lookbehind), iade_kargo niyetine gider.
            'match' => '/(?<!iade\s)(?<!iade)kargo\s*(ucreti|bedeli|parasi|ne\s*kadar|kac\s*(tl|lira|para))|ucretsiz\s*kargo|kargo\s*bedava|kac\s*tl\w*\s*(uzeri|ustu)\s*kargo/u',
        ],
        'indirim_kodu' => [
            'chip' => 'İndirim kodumu nasıl kullanırım?',
            'action' => 'faq',
            'query' => 'indirim kodu hediye çeki nasıl kullanılır sepet ödeme adımı',
            'min' => 0.28,
            'anchor' => [['kod', 'cek', 'kupon', 'indirim']],
            'match' => '/(indirim|kupon|hediye|promosyon|cark)\w*\s*(cek\w*\s*)?(kod\w*\s*)?(nasil|nerede|nereye)\s*(kullan|gir|uygula|kazanc)|(indirim|kupon|hediye\s*cek|promosyon|cark)\w*\s*kod\w*\s*(kullan|gir|uygula)|kodu\w*\s*(nasil|nerede|nereye)\s*(kullan|gir|uygula)/u',
        ],
        'beden' => [
            'chip' => 'Hangi numarayı almalıyım?',
            'action' => 'faq',
            'query' => 'tam kalıp numara beden tablosu cm',
            'min' => 0.30,
            'anchor' => [['beden', 'numara', 'kalip', 'olcu']],
            // Tam cevap iki kayıt: kalıbın tam/dar olduğu + cm-numara tablosu
            'docs' => 2,
            'match' => '/kali[pb]\w*|hangi\s*(numara|beden)|(numara|beden)\w*\s*(tablo|secmeli|almali|onerir)|beden\s*tablo|(buyuk|kucuk|dar|genis)\s*m[uy]?\w*\s*(kaliyor|geliyor|gelir)|ayak\w*\s*(uzunlugu|olcu)|kac\s*numara\s*(alma|giy|onerir)/u',
        ],
    ];

    /**
     * Bu niyetlerin cevabı sonrasında müşteriye gösterilecek TAKİP çipleri.
     * Amaç: müşteri bir sonraki adımını yazmak zorunda kalmasın (5 Eyl'de
     * devirlerin yarısı, cevabı elimizde olan ikinci bir soruydu).
     */
    protected const FOLLOW_UPS = [
        'iade_nasil' => ['iade_suresi', 'iade_para'],
        'iade_suresi' => ['iade_nasil', 'iade_para'],
        'iade_para' => ['iade_nasil', 'siparis_durum'],
        'degisim' => ['iade_nasil', 'urun_oneri'],
        'kargo_sure' => ['siparis_durum', 'iade_nasil'],
        'kargo_ucreti' => ['kargo_sure', 'siparis_durum'],
        'iade_kargo' => ['iade_nasil', 'iade_para'],
        'indirim_kodu' => ['urun_oneri', 'kargo_ucreti'],
        'hesabim' => ['siparis_durum', 'iade_nasil'],
        'beden' => ['urun_oneri', 'degisim'],
        'stok_haber' => ['urun_oneri', 'beden'],
        'siparis_durum' => ['kargo_sure', 'iade_nasil'],
    ];

    /**
     * Çok sorulan sorular şikâyet cümlesinin İÇİNDE geçtiğinde hazır cevap
     * yanlış olur ("iadem 3 haftadır gelmedi, rezalet") — bu mesajlar normal
     * akışa (kapılar → temsilciye devir) bırakılır.
     */
    protected const COMPLAINT = '/sikayet|magdur|rezalet|berbat|kandir|dolandir|hala\s*gelme|gelmedi|ulasmadi|yanlis\s*(urun|numara|beden|renk)|kusurlu|bozuk|yirtik|ayip|avukat|tuketici\s*hakem|iade\w*\s*(gelmedi|yatmadi|olmadi)'
        // 8 Eyl: GECİKMİŞ sipariş, "kargom ne zaman gelir" genel sorusu DEĞİLDİR.
        // "2 Eylülde sipariş verdim hala paketleniyor" diyene kesim saatini
        // anlatmak müşteriyi duymamak olur — bu mesajlar sipariş sorgusuna /
        // temsilciye gider (sohbet #708).
        . '|hala\s*(paketlen|hazirlan|bekli|kargoya\s*veril)|gecikm|gecikti|gunu\s*gecti|gun\s*oldu\s*hala/u';

    /**
     * Mesaj bir ŞİKÂYET mi? (hazır cevapla kapatılmamalı, insana gitmeli)
     *
     * ChatbotService devir akışından çıkış kararında da bunu sorar: devredilmiş
     * bir şikâyetin ortasında geçen "kaç gün sürüyor" sorusu hazır cevapla
     * kapatılırsa müşteri kendini duyulmamış hisseder.
     */
    public static function isComplaint(string $message): bool
    {
        return (bool) preg_match(self::COMPLAINT, static::clean($message));
    }

    /**
     * Çiplerin GÖRÜNÜM sırası. CATALOG sırası eşleştirme önceliğine göre
     * (özelden genele) dizilidir; müşterinin gördüğü sıra ise sohbetin doğal
     * akışıdır: önce alışveriş, sonra sipariş, sonra iade, sonra bilgi.
     */
    protected const CHIP_ORDER = [
        'urun_oneri', 'siparis_durum', 'kargo_sure', 'iade_nasil',
        'iade_para', 'degisim', 'beden', 'stok_haber',
    ];
    // NOT: kargo_ucreti / iade_kargo / indirim_kodu / hesabim çip olarak
    // GÖSTERİLMEZ (8 çip zaten çok) — yalnız müşteri kendi cümlesiyle sorunca
    // yakalanır. Devir sebeplerinin başında geliyorlardı.

    /** Varsayılan çip seti (tenant panelden değiştirmediyse bu gösterilir). */
    public static function defaultChips(): array
    {
        return array_values(array_map(fn ($k) => self::CATALOG[$k]['chip'], self::CHIP_ORDER));
    }

    /**
     * Mesaj çok sorulan sorulardan birine mi düşüyor?
     *
     * @return string|null  niyet anahtarı
     */
    public static function detect(string $message): ?string
    {
        $m = static::clean($message);

        // Uzun mesaj = anlatılan bir durum; hazır cevap değil, okunmuş bir cevap
        // gerekir. Çipler ve müşterinin kısa soruları bu sınırın altında kalır.
        if ($m === '' || mb_strlen($m) > 120) {
            return null;
        }

        if (preg_match(self::COMPLAINT, $m)) {
            return null;
        }

        foreach (self::CATALOG as $key => $intent) {
            if (preg_match($intent['match'], $m)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * İstek TARİFSİZ mi? ("Ürün önerisi istiyorum" evet, "44 numara gri spor
     * ayakkabı önerir misiniz" hayır.)
     *
     * Neden gerekli: tarifsiz istekte doğru cevap vitrindir (en çok tercih
     * edilenler); tarif VARSA vitrin göstermek müşterinin söylediğini duymamak
     * olur — o arama normal akışta yapılır.
     */
    public static function isBareAsk(string $message, string $intent): bool
    {
        return static::residualTokens($message, $intent) === [];
    }

    /**
     * Mesajdan niyet kalıbını ve bu mağazada AYIRT EDİCİ OLMAYAN kelimeleri
     * ("ürün", "model", "ayakkabı", "öneri") attıktan sonra kalanlar.
     *
     * @return array<int, string>
     */
    protected static function residualTokens(string $message, string $intent): array
    {
        $generic = ['urun', 'oner', 'tavsiy', 'model', 'ayakkab', 'goster', 'bulur', 'hangi',
            'bilmiyorum', 'karar', 'sizin', 'sizde', 'bana', 'alsam', 'alacag', 'alayim',
            'secenek', 'fikir', 'yardim', 'lazim', 'gerek', 'almak', 'bakmak', 'yeni'];

        $out = [];
        foreach (ChatKnowledge::tokens($message) as $t) {
            foreach ($generic as $g) {
                if (str_starts_with($t, $g)) {
                    continue 2;
                }
            }
            // Çipin kendi kelimeleri kalıntı sayılmaz
            if (in_array($t, ChatKnowledge::tokens((string) (self::CATALOG[$intent]['chip'] ?? '')), true)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * faq niyetinin cevabı: mağazanın kendi onaylı metni, müşteriye
     * gönderilebilir hâle getirilmiş olarak.
     *
     * @return array{text: string, source: string}|null
     */
    public static function faqAnswer(int $tenantId, string $intent): ?array
    {
        $def = self::CATALOG[$intent] ?? null;
        if (! $def || ($def['action'] ?? '') !== 'faq') {
            return null;
        }

        $docs = ChatKnowledge::topDocs($tenantId, (string) $def['query'], [
            'min' => (float) ($def['min'] ?? ChatKnowledge::MIN_SCORE),
            'prefer_url' => ! empty($def['prefer_url']),
            'require_url' => ! empty($def['require_url']),
            'anchor_content' => (array) ($def['anchor_content'] ?? []),
            'anchor' => (array) ($def['anchor'] ?? []),
        ], (int) ($def['docs'] ?? 1));

        if ($docs === []) {
            return null;
        }

        $text = trim(implode("\n\n", array_filter(array_map(
            fn ($d) => static::presentable((string) $d['content']),
            $docs
        ))));

        $source = implode(' + ', array_map(fn ($d) => (string) $d['title'], $docs));

        // CANLI KAYNAK — mağazanın ayarından okunan, saate duyarlı gerçek.
        // Hazır cevap metni zamanla eskiyebilir ("1-3 iş günü içinde kargoya
        // verilir" derken vitrinde "bugün kargoda" yazıyordu); bu satır her
        // seferinde yeniden hesaplanır ve metnin ÖNÜNE geçer.
        if (($def['live'] ?? '') === 'shipping') {
            $live = ChatShipping::answer($tenantId, null, $text);
            if ($live !== null) {
                return ['text' => $live, 'source' => 'Kargo takvimi (canlı) + ' . $source];
            }
        }

        return $text === '' ? null : [
            'text' => $text,
            'source' => $source,
        ];
    }

    /**
     * Cevabın ardından gösterilecek takip çipleri (en fazla 3).
     *
     * @return array<int, string>
     */
    public static function followUps(string $intent, array $chips): array
    {
        $out = [];
        foreach (self::FOLLOW_UPS[$intent] ?? [] as $key) {
            $chip = self::CATALOG[$key]['chip'] ?? null;
            if ($chip === null) {
                continue;
            }
            // Tenant çipi kendi sözcükleriyle yazdıysa onun metnini kullan
            foreach ($chips as $c) {
                if (static::detect($c) === $key) {
                    $chip = $c;
                    break;
                }
            }
            $out[] = $chip;
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /**
     * Personelin hazır cevabı → sohbet balonuna uygun metin.
     * • `**kalın**` işaretleri temizlenir (widget markdown render etmez, düz
     *   yıldız olarak çıkıyordu — WhatsApp'ta da aynı kusur var).
     * • Baştaki selamlama atılır: bot zaten karşılamış oluyor.
     * • Fazla boş satırlar tek satıra iner.
     */
    protected static function presentable(string $content): string
    {
        $t = trim($content);
        $t = (string) preg_replace('/\*{1,2}([^*]+)\*{1,2}/u', '$1', $t);
        $stripped = (string) preg_replace('/^\s*(merhaba|selam|iyi\s*günler)[^\n,.!]*[,.!]?\s*[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]*\s*/iu', '', $t);
        if (trim($stripped) !== '') {
            // "Merhaba efendim, iadeniz..." → "İadeniz..." (küçük harfle başlamasın)
            $first = mb_substr($stripped, 0, 1);
            $first = $first === 'i' ? 'İ' : mb_strtoupper($first, 'UTF-8');
            $t = $first . mb_substr($stripped, 1);
        }
        $t = (string) preg_replace('/\n{3,}/u', "\n\n", $t);

        return trim($t);
    }

    /**
     * Çip metni emoji/işaret taşıyor olabilir; eşleştirme SADELEŞTİRİLMİŞ
     * (ASCII, küçük harf) metinle yapılır — bu yüzden yukarıdaki `match`
     * kalıpları da Türkçe karakter İÇERMEZ ("değişim" değil "degis").
     */
    protected static function clean(string $message): string
    {
        $m = ChatKnowledge::normalize(trim($message));
        $m = (string) preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', ' ', $m);

        return trim((string) preg_replace('/\s+/u', ' ', $m));
    }
}
