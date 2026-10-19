<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AI Chatbot — DOĞRULUK TEMELİ (grounding). Bkz docs/ai-chatbot.md.
 *
 * Bot mağaza bilgisi içeren hiçbir soruyu "kendi bilgisiyle" cevaplamaz; cevap
 * yalnız burada bulunan ONAYLI metinlere dayanabilir. Kaynaklar:
 *   1) knowledge_base (panelden yönetilen bilgi bankası)
 *   2) quick_replies (personelin gerçek hazır cevapları — müşteri hizmetlerinin
 *      kendi yazdığı, onaylı metinler; en güvenilir kaynak)
 *   3) canlı sistem gerçekleri (iade penceresi vb. — tenant ayarlarından okunur,
 *      sabit metne gömülmez ki ayar değişince bot da güncellensin)
 *
 * Eşleşme bulunamazsa match() null döner ve ChatbotService soruyu CEVAPLAMAZ,
 * müşteri temsilcisine devreder.
 *
 * NEDEN kendi skorlayıcımız: korpus küçük (~40 kayıt), Türkçe ekler yüzünden
 * LIKE '%soru%' pratikte hiç tutmuyordu (eski knowledgeContext'in bug'ı: TÜM
 * soru cümlesini alt dize olarak arıyordu). Burada Türkçe normalizasyon +
 * kök-önek eşleşmesi + eşanlamlı genişletme ile deterministik skorlama var.
 */
class ChatKnowledge
{
    /**
     * Skor eşiği: bunun altında "bilmiyorum" sayılır → temsilciye devir.
     * 4 Eyl kalibrasyonu: gerçek müşteri sorularında doğru eşleşmeler 0.67+,
     * alakasız sorular ("hava durumu") 0.50'de kalıyor.
     */
    public const MIN_SCORE = 0.2;

    /** Sorudan atılacak dolgu kelimeleri (normalize edilmiş halleriyle) */
    protected const STOPWORDS = [
        'acaba', 'ama', 'ben', 'bana', 'beni', 'bir', 'biraz', 'bu', 'bunu', 'bunun',
        'çok', 'cok', 'daha', 'de', 'da', 'diye', 'eger', 'için', 'icin', 'ile', 'ise',
        'kadar', 'ki', 'mi', 'mu', 'mı', 'ne', 'nedir', 'niye', 'nasil', 'olur', 'olsun',
        'sonra', 'siz', 'size', 'sizin', 'su', 'sey', 'var', 'yok', 'ya', 'yani', 've',
        'merhaba', 'selam', 'iyi', 'gunler', 'tesekkur', 'tesekkurler', 'lutfen', 'rica',
        'istiyorum', 'ediyorum', 'edebilir', 'miyim', 'misiniz', 'musunuz', 'peki',
    ];

    /**
     * Müşteri diliyle mağaza dilini köprüleyen eşanlamlılar. Soru bu kelimeleri
     * içeriyorsa arama sorgusuna sağdaki kökler de eklenir (kayıt başlıkları
     * personel diliyle yazılmış: "Kargo süresi (termin)", "CM - Numara tablosu").
     */
    protected const SYNONYMS = [
        'gelir' => ['kargo', 'termin', 'teslim'],
        'gelecek' => ['kargo', 'termin', 'teslim'],
        'ulasir' => ['kargo', 'termin', 'teslim'],
        'ulasi' => ['kargo', 'teslim'],
        'gonder' => ['kargo'],
        'gecikt' => ['kargo', 'gecikme'],
        'nerede' => ['kargo', 'siparis', 'takip'],
        'takip' => ['kargo', 'siparis'],
        'numara' => ['beden', 'kalip', 'tablo', 'siparis'],
        'ayak' => ['beden', 'kalip', 'numara', 'tablo'],
        'olcu' => ['beden', 'kalip', 'tablo'],
        'kalip' => ['beden', 'numara'],
        'buyuk' => ['beden', 'kalip'],
        'kucuk' => ['beden', 'kalip'],
        'geri' => ['iade'],
        'para' => ['iade', 'odeme'],
        'degis' => ['degisim', 'iade'],
        'iptal' => ['iade', 'siparis'],
        'kusur' => ['garanti', 'kusurlu'],
        'bozul' => ['garanti', 'kusurlu'],
        'yirtil' => ['garanti', 'kusurlu'],
        'soktu' => ['garanti', 'kusurlu'],
        'yika' => ['temizlik'],
        'temizle' => ['temizlik'],
        'bakim' => ['temizlik'],
        'indirim' => ['kampanya'],
        'kupon' => ['kampanya', 'indirim'],
        'kod' => ['kampanya', 'indirim'],
        'stok' => ['stok', 'yenilen'],
        'tukendi' => ['stok'],
        'bedeni' => ['beden', 'stok'],
        'kapida' => ['odeme'],
        'taksit' => ['odeme'],
        'havale' => ['odeme'],
        'erkek' => ['unisex'],
        'kadin' => ['unisex'],
        'bayan' => ['unisex'],
        'su' => ['su itici'],
        'yagmur' => ['su itici'],
        'gecir' => ['itici', 'kumas'],
        'sugecirmez' => ['su itici'],
        'islan' => ['su itici'],
        'rahat' => ['ortopedik'],
        'taban' => ['ortopedik'],
        'topuk' => ['topuk'],
        'telefon' => ['musteri hizmetleri', 'numara'],
        'aramak' => ['musteri hizmetleri'],
    ];

    /**
     * Soruya en uygun onaylı kayıtları döndürür.
     *
     * @return array{score: float, context: string, sources: array<int, string>}|null
     *         null = güvenilir eşleşme yok (bot cevaplamamalı)
     */
    public static function match(int $tenantId, string $question, int $limit = 3): ?array
    {
        $scored = static::rank($tenantId, $question);
        if ($scored === []) {
            return null;
        }

        $best = $scored[0]['score'];
        if ($best < static::MIN_SCORE) {
            return null;
        }

        // En iyi skorun yarısının altındakileri alma (alakasız gürültü)
        $picked = array_slice(array_filter($scored, fn ($d) => $d['score'] >= $best * 0.6), 0, $limit);

        return [
            'score' => round($best, 3),
            'context' => implode("\n\n", array_map(
                fn ($d) => "## {$d['title']}\n" . mb_substr($d['content'], 0, 1200),
                $picked
            )),
            'sources' => array_map(fn ($d) => $d['title'], $picked),
        ];
    }

    /**
     * TEK bir onaylı kaydı döndürür (metni müşteriye BİREBİR gidecekse).
     *
     * ChatIntents bunu kullanır: "İade nasıl yapılır?" gibi çok sorulan
     * sorularda cevabı LLM'e yazdırmak yerine personelin kendi onaylı metnini
     * aynen göndeririz — sıfır uydurma, sıfır maliyet, panelden güncellenebilir.
     *
     * @param  array{min?: float, prefer_url?: bool}  $opts
     *         prefer_url: yakın skorlu kayıtlar arasında LİNK içereni seç
     *         (iade/iptal gibi konularda müşteriye asıl lazım olan linktir).
     * @return array{title: string, content: string, score: float}|null
     */
    public static function best(int $tenantId, string $question, array $opts = []): ?array
    {
        return static::topDocs($tenantId, $question, $opts, 1)[0] ?? null;
    }

    /**
     * En iyi $limit onaylı kayıt. Bazı sorularda tam cevap İKİ kayıttır
     * (ör. "hangi numarayı almalıyım" = kalıp bilgisi + cm/numara tablosu).
     *
     * @param  array{min?: float, prefer_url?: bool, require_url?: bool, anchor?: array<int, array<int, string>>, anchor_content?: array<int, array<int, string>>}  $opts
     *         anchor: kaydın KONUYA ait olduğunun kanıtı. Her grup için kaydın
     *         BAŞLIĞINDA o gruptan en az bir kelime geçmeli. Başlık şartı
     *         bilinçli: içerik geçişte konuyu anıyor olabilir, başlık ise kaydın
     *         NE HAKKINDA olduğunu söyler. Skor tek başına yetmiyor — korpusu
     *         küçük bir tenant'ta "iade nasıl yapılır" sorusu mağazanın "İndirim
     *         talebi" kaydını en yüksek skorlu kayıt yapabiliyor (5 Eyl, t13).
     *         anchor_content: aynı şart, ama başlık YA DA içerikte aranır — konu
     *         başlıkta adlandırılmıyor olabilir ("Kolay iade nasıl yapılır"
     *         başlığında süre yok, süre metnin içinde).
     *         require_url: konu bir LİNKLE cevaplanıyorsa (iade talebi açma gibi)
     *         ve mağazanın hiçbir kaydında link yoksa deterministik cevap VERİLMEZ.
     * @return array<int, array{title: string, content: string, score: float}>
     */
    public static function topDocs(int $tenantId, string $question, array $opts = [], int $limit = 1): array
    {
        $scored = static::rank($tenantId, $question);

        foreach ((array) ($opts['anchor'] ?? []) as $group) {
            $scored = array_values(array_filter(
                $scored,
                fn ($d) => static::hasAnyStem($d['ttitle'] ?? [], (array) $group)
            ));
        }

        foreach ((array) ($opts['anchor_content'] ?? []) as $group) {
            $scored = array_values(array_filter(
                $scored,
                fn ($d) => static::hasAnyStem(
                    array_merge($d['ttitle'] ?? [], $d['tcontent'] ?? []),
                    (array) $group
                )
            ));
        }

        if (! empty($opts['require_url'])) {
            $scored = array_values(array_filter(
                $scored,
                fn ($d) => (bool) preg_match('~https?://~i', $d['content'])
            ));
        }

        if ($scored === []) {
            return [];
        }

        $min = (float) ($opts['min'] ?? static::MIN_SCORE);
        $top = $scored[0]['score'];
        if ($top < $min) {
            return [];
        }

        $pick = $scored[0];

        // Link tercihi: en iyi skorun %75'i içinde kalan bir kayıt URL içeriyorsa
        // onu seç. Sebep (5 Eyl): t1'de "iade nasıl yapılır" sorusu, müşteriye
        // adım adım süreci anlatan kayda düşüyordu; müşterinin ihtiyacı ise
        // iade talebini açtığı link.
        if (! empty($opts['prefer_url']) || ! empty($opts['require_url'])) {
            foreach ($scored as $d) {
                if ($d['score'] < $top * 0.75) {
                    break;
                }
                if (preg_match('~https?://~i', $d['content'])) {
                    $pick = $d;
                    break;
                }
            }
        }

        $out = [['title' => $pick['title'], 'content' => $pick['content'], 'score' => round($pick['score'], 3)]];

        // İkinci kayıt yalnız GERÇEKTEN ilgiliyse eklenir (en iyi skorun %60'ı);
        // yoksa müşteriye alakasız bir paragraf daha gitmiş olur.
        foreach ($scored as $d) {
            if (count($out) >= $limit) {
                break;
            }
            if ($d['title'] === $pick['title'] || $d['score'] < $top * 0.6) {
                continue;
            }
            $out[] = ['title' => $d['title'], 'content' => $d['content'], 'score' => round($d['score'], 3)];
        }

        return $out;
    }

    /**
     * Token listesinde verilen köklerden en az biri geçiyor mu?
     * Kök BAŞTAN eşleşir: "gun" → "günde" evet, "bugün" hayır.
     *
     * @param  array<int, string>  $tokens
     * @param  array<int, string>  $stems
     */
    protected static function hasAnyStem(array $tokens, array $stems): bool
    {
        foreach ($tokens as $t) {
            foreach ($stems as $stem) {
                if (str_starts_with($t, $stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Soruyu korpusa karşı skorlar, skoru sıfırdan büyük kayıtları büyükten
     * küçüğe döndürür. Eşik UYGULAMAZ — çağıran karar verir.
     *
     * @return array<int, array{score: float, title: string, content: string}>
     */
    protected static function rank(int $tenantId, string $question): array
    {
        $tokens = static::queryTokens($question);
        if (empty($tokens)) {
            return [];
        }

        $corpus = static::corpus($tenantId);
        if (empty($corpus)) {
            return [];
        }

        $idf = static::idf($tokens, $corpus);

        // Korpusta HİÇ geçmeyen kelimeler skorlamadan çıkarılır. Mağaza bu
        // kelimeleri kullanmıyor demektir; paydada tutmak doğru kaydı da
        // cezalandırıyordu ("kadın da giyebilir mi" → Unisex kaydını kaçırıyordu).
        $tokens = array_intersect_key($tokens, array_filter($idf, fn ($v) => $v !== null));
        if (empty($tokens)) {
            return [];
        }

        $scored = [];
        foreach ($corpus as $doc) {
            $score = static::score($tokens, $doc, $idf);
            if ($score > 0) {
                $scored[] = ['score' => $score] + $doc;
            }
        }

        if (empty($scored)) {
            return [];
        }

        // Geriye tek bir sorgu kelimesi kaldıysa ve o da yaygın bir kelimeyse
        // ("ürün", "sipariş") skor yanıltıcı biçimde 1.0 çıkar — dayanak sayma.
        if (count($tokens) === 1) {
            $only = array_key_first($tokens);
            if (($idf[$only] ?? 0) < 1.0) {
                return [];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * Onaylı metin havuzu (5 dk cache). knowledge_base + aktif hazır cevaplar +
     * canlı sistem gerçekleri.
     *
     * @return array<int, array{title: string, content: string, ttitle: array, tcontent: array}>
     */
    public static function corpus(int $tenantId): array
    {
        return Cache::remember("chat:kb:corpus:{$tenantId}", 300, function () use ($tenantId) {
            $docs = [];

            foreach (DB::table('knowledge_base')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get(['title', 'content']) as $row) {
                $docs[] = ['title' => (string) $row->title, 'content' => (string) $row->content];
            }

            // Personelin gerçek hazır cevapları — en güvenilir kaynak.
            // Selamlama/emoji gibi içeriksiz kısa kayıtlar elenir.
            foreach (DB::table('quick_replies')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get(['title', 'content']) as $row) {
                $content = trim((string) $row->content);
                if (mb_strlen($content) < 40) {
                    continue;
                }
                $docs[] = ['title' => (string) $row->title, 'content' => $content];
            }

            foreach (static::liveFacts($tenantId) as $fact) {
                $docs[] = $fact;
            }

            return array_map(function ($d) {
                $d['ttitle'] = static::tokens($d['title']);
                $d['tcontent'] = static::tokens($d['content']);

                return $d;
            }, $docs);
        });
    }

    /**
     * Canlı sistem gerçekleri — tenant ayarlarından okunan, sabit metne
     * gömülmemesi gereken bilgiler.
     *
     * ⚠️ İADE SÜRESİ BİLEREK BURADA DEĞİL (4 Eyl kararı). Sebep: iade modülünün
     * `return_window_days` ayarı pencereyi **sipariş tarihinden** sayar (t1'de 20 gün),
     * müşteriye söylenen süre ise **teslim tarihinden** sayılır (14 gün) — aradaki
     * ~6 gün kargo/teslimat payıdır. İki sayı da doğru ama referans noktaları farklı;
     * ham ayarı müşteriye "teslimden sonra 20 gün" diye aktarmak YANLIŞ olur.
     * Müşteriye gidecek ifade personelin hazır cevabında yaşar
     * (quick_replies "Kolay iade nasıl yapılır") ve müşteri hizmetleri onu panelden
     * günceller — doğru sahiplik orasıdır.
     *
     * Buraya yalnızca referans noktası tek anlamlı olan gerçekler eklenmelidir.
     *
     * @return array<int, array{title: string, content: string}>
     */
    protected static function liveFacts(int $tenantId): array
    {
        $facts = [];

        // Kargoya çıkış saati/günleri: referans noktası tek anlamlı (mağazanın
        // kendi kargo takvimi) ve ürün sayfasındaki rozetle aynı kaynak.
        // 8 Eyl: bot "1-3 iş günü içinde kargoya verilir" derken vitrinde
        // "bugün kargoda" yazıyordu — LLM yolu da artık doğruyu görsün.
        $shipping = ChatShipping::fact($tenantId);
        if ($shipping !== null) {
            $facts[] = $shipping;
        }

        return $facts;
    }

    /** Ayar/kayıt değişince çağır. */
    public static function forget(int $tenantId): void
    {
        Cache::forget("chat:kb:corpus:{$tenantId}");
    }

    /**
     * Skor = sorunun kaç token'ının kayıtta karşılandığı (0..1 kapsama oranı).
     * Başlıkta geçen token 2 kat sayılır (başlıklar konuyu birebir adlandırıyor)
     * ve her token IDF ile ağırlıklandırılır: "kargo" gibi her kayıtta geçen
     * kelimeler az, "ortopedik" gibi ayırt edici kelimeler çok puan getirir.
     * IDF olmadan uzun kayıtlar ("Ulaşmayan iade kargo takip") her soruda
     * gürültü olarak üste çıkıyordu (4 Eyl kalibrasyonu).
     */
    protected static function score(array $qTokens, array $doc, array $idf): float
    {
        $hit = 0.0;
        $max = 0.0;

        foreach ($qTokens as $t => $qw) {
            $w = ($idf[$t] ?? 1.0) * $qw;
            $max += 2.0 * $w;

            if (static::hasToken($doc['ttitle'], $t)) {
                $hit += 2.0 * $w;
            } elseif (static::hasToken($doc['tcontent'], $t)) {
                $hit += 1.0 * $w;
            }
        }

        return $max > 0 ? $hit / $max : 0.0;
    }

    /**
     * Sorgu token'larının ters belge frekansı (IDF). Korpus küçük olduğu için
     * her istekte hesaplanır; maliyeti ihmal edilebilir.
     *
     * @return array<string, float|null>  null = korpusta hiç geçmiyor
     */
    protected static function idf(array $qTokens, array $corpus): array
    {
        $n = max(1, count($corpus));
        $out = [];

        foreach (array_keys($qTokens) as $t) {
            $df = 0;
            foreach ($corpus as $doc) {
                if (static::hasToken($doc['ttitle'], $t) || static::hasToken($doc['tcontent'], $t)) {
                    $df++;
                }
            }
            // df=0 → korpusta hiç geçmiyor; null ile işaretlenip elenir
            $out[$t] = $df === 0 ? null : log(($n + 1) / ($df + 1)) + 0.25;
        }

        return $out;
    }

    /**
     * Türkçe ek toleransı. Türkçe eklemeli bir dil: "değiştirebilir" ile
     * "değişim" birbirinin öneki DEĞİL, ortak gövdeleri "degis". Bu yüzden
     * önek karşılaştırmasına ek olarak ilk 5 harflik kaba gövde eşleşmesi
     * kullanılır (4 Eyl: "ürünü değiştirebilir miyim" hiçbir kayda bağlanmıyor,
     * geriye tek başına "urun" kalıyor ve alakasız kayıtlar dönüyordu).
     */
    protected static function hasToken(array $haystack, string $needle): bool
    {
        $nStem = mb_substr($needle, 0, 5);

        foreach ($haystack as $h) {
            if ($h === $needle) {
                return true;
            }
            $min = min(mb_strlen($h), mb_strlen($needle));
            if ($min >= 4 && (str_starts_with($h, $needle) || str_starts_with($needle, $h))) {
                return true;
            }
            if ($min >= 5 && mb_substr($h, 0, 5) === $nStem) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ham metin → anlamlı kelimeler. EŞANLAMLI GENİŞLETME YAPMAZ; kayıtlar
     * kendi kelimeleriyle indekslenir (genişletme yalnız SORUYA uygulanır,
     * bkz queryTokens).
     *
     * @return array<int, string>
     */
    public static function tokens(string $text): array
    {
        $norm = static::normalize($text);
        $parts = preg_split('/[^a-z0-9]+/', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3 || in_array($p, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }

    /**
     * Sorunun ağırlıklı token'ları: müşterinin KENDİ kelimeleri tam ağırlık (1.0),
     * eşanlamlı köprüyle eklenenler yarım ağırlık (0.4).
     *
     * Neden ağırlık: eşanlamlılar tek bir kelimeden 3-4 token türetebiliyor ve
     * bunlar müşterinin asıl ayırt edici kelimesini oy çokluğuyla eziyordu
     * (4 Eyl: "ayakkabinin tabani ortopedik mi" → beden tablosu dönüyordu).
     *
     * @return array<string, float>
     */
    public static function queryTokens(string $question): array
    {
        $out = [];

        foreach (static::tokens($question) as $p) {
            $out[$p] = 1.0;

            foreach (self::SYNONYMS as $key => $extra) {
                // "ayakkabinin" ile "ayak" eşleşmemeli. İki yol: kısa kökler (4 harf)
                // en fazla 3 harflik ekle eşleşir; 5+ harflik kökler düz önek olarak
                // eşleşir (Türkçe uzun ekler: "degis" → "degistirebilir").
                $keyLen = mb_strlen($key);
                $isMatch = $p === $key
                    || (str_starts_with($p, $key) && ($keyLen >= 5 || mb_strlen($p) - $keyLen <= 3));
                if (! $isMatch) {
                    continue;
                }
                foreach ($extra as $e) {
                    foreach (static::tokens($e) as $piece) {
                        $out[$piece] ??= 0.4;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Türkçe karakterleri ASCII'ye indirger, küçük harfe çevirir.
     *
     * ⚠️ Büyük harfler ÖNCE eşlenir: mb_strtolower('İ') = "i" + U+0307
     * (birleşen nokta) döndürüyor ve bu ayraç sayıldığı için "İade" iki ayrı
     * token'a ("i" + "ade") bölünüyordu — yani İ ile başlayan HER soru
     * ("İade nasıl yapılır?") yanlış kayda eşleşiyordu (5 Eyl tespiti).
     * Artakalan birleşen işaretler yine de temizlenir.
     */
    public static function normalize(string $text): string
    {
        $text = strtr($text, [
            'İ' => 'i', 'I' => 'i', 'Ğ' => 'g', 'Ü' => 'u',
            'Ş' => 's', 'Ö' => 'o', 'Ç' => 'c', 'Â' => 'a', 'Î' => 'i', 'Û' => 'u',
        ]);

        $text = mb_strtolower($text, 'UTF-8');

        $text = strtr($text, [
            'ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c',
            'â' => 'a', 'î' => 'i', 'û' => 'u',
        ]);

        // Birleşen aksan işaretleri (U+0300-U+036F) — ayraç sayılmasınlar
        return (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $text);
    }
}
