<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Akıllı Arama — Türkçe sorgu anlama (query understanding).
 *
 * Akış: ham sorgu → normalize (tr-lower + deascii) → facet çıkarımı
 * (Renk/Beden/Cinsiyet/Kategori/Marka — tenant'ın GERÇEK kataloğundan DİNAMİK sözlükle,
 * hardcode değil) → kalan serbest terimler + eşanlamlı genişletme → intent.
 *
 * Kural-bazlı çekirdek hızlı ve deterministik; LLM yalnız belirsizlikte ([[llmEnrich]])
 * devreye girer (config search.understanding). "kırmızı 44" → {renk:Kırmızı, beden:44}.
 */
class QueryUnderstanding
{
    /** Kitleye uygun kategori bulunamadığında facet'e yazılan nöbetçi değer (hiçbir ürünle eşleşmez). */
    public const NO_CATEGORY = '__none__';

    public function __construct(protected int $tenantId)
    {
    }

    public static function forTenant(int $tenantId): static
    {
        return new static($tenantId);
    }

    /**
     * Sorguyu ayrıştır.
     *
     * @return array{raw:string,normalized:string,facets:array,terms:array,expanded_terms:array,intent:string,llm_used:bool}
     */
    public function parse(string $raw): array
    {
        $max = (int) config('search.limits.query_max_length.max', 120);
        $raw = trim(mb_substr($raw, 0, $max));
        $normalized = $this->trLower($raw);

        $vocab = $this->vocabulary();

        $normTokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normTokens = $this->applySpelling($normTokens); // onaylı yazım düzeltmeleri (snaker→sneaker)
        $foldedTokens = array_map(fn ($t) => $this->fold($t), $normTokens);
        $consumed = array_fill(0, count($foldedTokens), false);

        $facets = [];

        // Marka: tam ifade eşleşmesi (kategori/cinsiyetten önce; marka adları cinsiyet
        // kelimesi içermez, çakışmaz)
        $m = $this->matchPhrase($foldedTokens, $consumed, $vocab['brands']);
        if ($m !== null) {
            $facets['brand'] = $m;
        }

        // Beden: kataloğun beden kümesinde olan tek token (sayısal veya STN gibi)
        foreach ($foldedTokens as $i => $tok) {
            if ($consumed[$i] || $tok === '') {
                continue;
            }
            if (isset($vocab['sizes'][$tok])) {
                $facets['beden'] = $vocab['sizes'][$tok];
                $consumed[$i] = true;
                break;
            }
        }

        // Kategori: kelime-önek çoklu eşleşme. Katalog kategorileri bileşik adlı
        // ("Kadın Bot", "Unisex Spor Ayakkabı", "Kar Botu", "Bot&Çizme") olduğundan
        // tek-değer eşleşme sonuçların çoğunu kaçırır: "bot" → 10 bot kategorisi,
        // "spor ayakkabı" → tüm spor ayakkabı kategorileri.
        // Renkten ÖNCE koşar: "kar botu"ndaki "kar" renk sözlüğüne (Kareli/Karamel)
        // kapılmadan kategoriye ("Kar Botu") bağlanabilsin.
        // CİNSİYETTEN de ÖNCE koşar: kategoriler cinsiyeti İÇERİR ("Kız Çocuk Spor Ayakkabı");
        // "kız çocuk" cinsiyete kapılırsa geriye genel "spor ayakkabı" kalıp TÜM cinsiyetlere
        // yayılır ve güvenilmez Cinsiyet spec'ine (çoğu ürün "Çocuk Unisex") katı filtre
        // koyar → çocuk aramaları 0-12 sonuca düşerdi. Kategori-önce bunu kökten çözer.
        // Hedef kitle: sorguda çocuk/erkek/kadın ipucu varsa kategori eşleşmesi o kitleye
        // daraltılır ("erkek bot" → "Erkek Çocuk Bot" ELENİR; "erkek çocuk" = çocuk).
        $audience = $this->audience($foldedTokens);
        $audienceSub = $this->audienceSub($foldedTokens, $audience);

        $displayLabels = [];
        $categorySpan = null;
        $categorySpans = [];
        $m = $this->matchMulti($foldedTokens, $consumed, $vocab['category_words'] ?? [], $audience);
        if ($m !== null) {
            if (empty($m['values'])) {
                // İfade kategoriye bağlanıyor ama bu kitlede karşılığı yok ("erkek çanta" →
                // yalnız "Erkek Çocuk Çanta" var): kategori yolu kapalı (nöbetçi değer),
                // isim-OR yolu açık — ad eşleşmesi yoksa 0 sonuç + öneri, gürültü değil.
                $facets['category'] = [self::NO_CATEGORY];
                $displayLabels['category'] = $this->spanLabel($normTokens, $m['i'], $m['len']);
            } else {
                $facets['category'] = count($m['values']) === 1 ? $m['values'][0] : $m['values'];
                if (count($m['values']) > 1) {
                    $displayLabels['category'] = $this->spanLabel($normTokens, $m['i'], $m['len']);
                }
            }
            // Kategoriye bağlanan ifadenin kendisi de saklanır: retrieval kategori
            // filtresini isim eşleşmesiyle OR'lar ("valiz" → kategorisi boş ama adında
            // valiz geçen ürünler kategori facet'i yüzünden görünmez kalmasın)
            $categorySpan = trim(implode(' ', array_slice($normTokens, $m['i'], $m['len'])));
            // İsim-OR için TİP ifadesi (katlanmış, kitle kelimeleri çıkarılmış, eşanlamlı
            // genişletilmiş): "erkek sneaker" → ['sneaker', 'spor ayakkabi']. Kitle
            // kısıtını Retriever isim üzerinde ayrıca uygular (audience).
            $categorySpans = $this->categorySpans(array_slice($foldedTokens, $m['i'], $m['len']));
        }

        // Cinsiyet: kategori TÜKETİLMEDEN kalan cinsiyet kelimelerinden (kategori zaten
        // cinsiyeti içeriyorsa buraya düşmez). "kadın çanta"da kategori "kadin"i yutmazsa
        // (Çanta tek kelimeyse) cinsiyet=Kadın burada yakalanır — salt cinsiyet aramaları için.
        $m = $this->matchPhrase($foldedTokens, $consumed, $vocab['genders']);
        if ($m !== null) {
            $facets['cinsiyet'] = $m;
        }

        // Renk: kelime-önek çoklu eşleşme — "kırmızı" → [Kırmızı, Kırmızı Siyah…]
        $m = $this->matchMulti($foldedTokens, $consumed, $vocab['color_words'] ?? []);
        if ($m !== null) {
            $facets['renk'] = count($m['values']) === 1 ? $m['values'][0] : $m['values'];
            if (count($m['values']) > 1) {
                $displayLabels['renk'] = $this->spanLabel($normTokens, $m['i'], $m['len']);
            }
        }

        // Kısmi kelime (prefix) — as-you-type: "kad"→Kadın (renk/kategori zaten önekli)
        $this->prefixMatchFacets($foldedTokens, $consumed, $vocab, $facets);

        // Kalan serbest terimler (semantic + lexical retrieval'a gider); gürültü
        // kelimeleri at ("36 numara sandalet"teki "numara" sonucu boğmasın)
        $stop = ['numara', 'beden', 'no', 'adet', 'tane', 'icin'];
        $terms = [];
        foreach ($normTokens as $i => $tok) {
            if (! $consumed[$i] && mb_strlen($tok) >= 2 && ! in_array($this->fold($tok), $stop, true)) {
                $terms[] = $tok;
            }
        }

        // Ürün kodu tespiti: harf+rakam karışık token (TBARZ1015, V2AR33) veya ≥5 haneli
        // salt rakam kod (Boise: 600401, 060409, varyant 110211-12) — retrieval'da
        // isim/SKU tam eşleşmesi bu kodla önceliklendirilir (trigram TBARZ1010 ile
        // TBARZ1015'i ayırt edemez, popüler kardeş kod tam eşleşmenin önüne geçiyordu).
        // Beden/numara (≤3 hane) kod sayılmaz.
        $codeTerms = array_values(array_filter($terms, function ($t) {
            return (bool) preg_match('/^(?=.*\d)(?:(?=.*\p{L})[\p{L}\p{N}][\p{L}\p{N}\-_.\/]{3,}|\d{5,}(?:[\-_.\/]\d+)*)$/u', $t);
        }));

        // Chip/etiket gösterimi: çoklu eşleşmede kullanıcının yazdığı ifade
        // ("Renk: Siyah"), tek değerde katalog değeri ("Kategori: Sneaker")
        $display = [];
        foreach ($facets as $k => $v) {
            $display[$k] = is_array($v) ? ($displayLabels[$k] ?? (string) $v[0]) : (string) $v;
        }

        return [
            'raw' => $raw,
            'normalized' => $normalized,
            'facets' => $facets,
            'facets_display' => $display,
            'terms' => $terms,
            'code_terms' => $codeTerms,
            'expanded_terms' => $this->expandSynonyms($terms),
            'category_span' => $categorySpan,
            'category_spans' => $categorySpans,
            'audience' => $audience,
            'audience_sub' => $audienceSub,
            'intent' => $this->intent($facets, $terms),
            'llm_used' => false,
        ];
    }

    /** Sorgudaki token aralığını Türkçe-duyarlı Başlık Biçimine çevir ("kar botu" → "Kar Botu"). */
    protected function spanLabel(array $normTokens, int $i, int $len): string
    {
        $words = array_slice($normTokens, $i, $len);
        $out = [];
        foreach ($words as $w) {
            $first = mb_substr($w, 0, 1, 'UTF-8');
            $first = strtr($first, ['i' => 'İ', 'ı' => 'I']);
            $out[] = mb_strtoupper($first, 'UTF-8') . mb_substr($w, 1, null, 'UTF-8');
        }

        return implode(' ', $out);
    }

    // ── Türkçe yardımcılar ──────────────────────────────────────────────

    /** Türkçe-duyarlı küçük harf (İ→i, I→ı). */
    public function trLower(string $s): string
    {
        $s = str_replace(
            ['İ', 'I', 'Ş', 'Ğ', 'Ü', 'Ö', 'Ç', 'Â', 'Î', 'Û'],
            ['i', 'ı', 'ş', 'ğ', 'ü', 'ö', 'ç', 'â', 'î', 'û'],
            $s
        );

        return mb_strtolower($s, 'UTF-8');
    }

    /** Deascii fold: tr-lower + aksan/Türkçe karakter sadeleştirme + alnum'a indirgeme. */
    public function fold(string $s): string
    {
        $s = $this->trLower($s);
        $s = strtr($s, [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    /**
     * Token dizisinde, sözlükteki en uzun (en çok kelimeli) ifadeyi bul; eşleşen
     * token'ları "consumed" işaretle. Sözlük: ['foldedPhrase' => 'OrijinalDeğer'] (uzunluk desc).
     */
    protected function matchPhrase(array $foldedTokens, array &$consumed, array $vocab): ?string
    {
        foreach ($vocab as $phrase => $value) {
            $parts = explode(' ', $phrase);
            $len = count($parts);
            $n = count($foldedTokens);
            for ($i = 0; $i + $len <= $n; $i++) {
                $ok = true;
                for ($j = 0; $j < $len; $j++) {
                    if ($consumed[$i + $j] || $foldedTokens[$i + $j] !== $parts[$j]) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    for ($j = 0; $j < $len; $j++) {
                        $consumed[$i + $j] = true;
                    }

                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Kelime-önek çoklu eşleşme: tüketilmemiş en uzun token aralığını (3→1 kelime),
     * aralıktaki HER sorgu kelimesi değerin bir kelimesinin kendisi ya da öneki (≥3 harf)
     * olacak şekilde kapsayan TÜM sözlük değerlerine bağlar.
     * "bot" → [Kadın Bot, Erkek Bot, Kadın Kar Botu…]; "kar botu" → [4 kar botu kategorisi];
     * "spor ayakkabı" → [Spor Ayakkabı, Unisex Spor Ayakkabı, Erkek Çocuk Spor Ayakkabı].
     *
     * @param  array  $wordVocab  [['words' => ['kadin','bot'], 'value' => 'Kadın Bot'], …]
     * @return array{values: string[], i: int, len: int}|null Eşleşen orijinal değerler + span konumu
     */
    protected function matchMulti(array $foldedTokens, array &$consumed, array $wordVocab, ?string $audience = null): ?array
    {
        if (empty($wordVocab)) {
            return null;
        }
        $n = count($foldedTokens);
        // Azami aralık 4 kelime: "Erkek Çocuk Spor Ayakkabı" 4 kelimedir; 3'te kalınca
        // "ayakkabı" serbest terim olarak artıyor ve sonuçlar alaka geçidine giriyordu.
        for ($len = min(4, $n); $len >= 1; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $span = [];
                for ($j = $i; $j < $i + $len; $j++) {
                    if ($consumed[$j] || $foldedTokens[$j] === '') {
                        $span = null;
                        break;
                    }
                    $span[] = $foldedTokens[$j];
                }
                if ($span === null) {
                    continue;
                }
                // İki geçiş: önce TAM kelime eşleşmesi ("kar"=="kar"), boşsa önek
                // ("sneak"→"sneaker"). Tam eşleşme varken önek komşuları karışmaz.
                foreach ([true, false] as $exactOnly) {
                    $values = [];
                    $blocked = false; // kitle filtresi olmasa eşleşecekti
                    foreach ($wordVocab as $row) {
                        if (! $this->wordsCover($span, $row['words'], $exactOnly)) {
                            continue;
                        }
                        if ($audience !== null && ! $this->audienceAllows($row['words'], $audience)) {
                            $blocked = true;
                            continue;
                        }
                        $values[] = $row['value'];
                    }
                    if (! empty($values) || ($blocked && $len >= 2)) {
                        for ($j = $i; $j < $i + $len; $j++) {
                            $consumed[$j] = true;
                        }

                        return ['values' => array_values(array_unique($values)), 'i' => $i, 'len' => $len];
                    }
                }
            }
        }

        return null;
    }

    /** Her sorgu kelimesi, değer kelimelerinden birinin kendisi ($exactOnly) ya da öneki mi (≥3 harf)? */
    protected function wordsCover(array $queryWords, array $valueWords, bool $exactOnly = false): bool
    {
        foreach ($queryWords as $qw) {
            $hit = false;
            foreach ($valueWords as $vw) {
                if ($vw === $qw || (! $exactOnly && strlen($qw) >= 3 && strlen($vw) > strlen($qw) && str_starts_with($vw, $qw))) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    // ── Hedef kitle (audience) ──────────────────────────────────────────

    /** config search.turkish.audience → ['child' => ['cocuk',…], 'male' => [...], 'female' => [...]] (katlanmış). */
    protected function audienceWords(): array
    {
        $out = [];
        foreach ((array) config('search.turkish.audience', []) as $key => $words) {
            $out[$key] = array_values(array_filter(array_map(fn ($w) => $this->fold((string) $w), (array) $words)));
        }

        return $out;
    }

    /**
     * Sorgunun hedef kitlesi: child > (male xor female) > null. Çocuk ipucu her zaman
     * baskındır ("erkek çocuk" = çocuk); hem erkek hem kadın geçiyorsa kısıt yok.
     */
    protected function audience(array $foldedTokens): ?string
    {
        $aw = $this->audienceWords();
        $has = fn (string $k) => ! empty(array_intersect($foldedTokens, $aw[$k] ?? []));
        if ($has('child') || $has('girl')) {
            return 'child';
        }
        $male = $has('male');
        $female = $has('female');
        if ($male xor $female) {
            return $male ? 'male' : 'female';
        }

        return null;
    }

    /** Çocuk kitlesinde alt-cinsiyet: "erkek çocuk" → male, "kız çocuk"/"kız" → female, "çocuk" → null. */
    protected function audienceSub(array $foldedTokens, ?string $audience): ?string
    {
        if ($audience !== 'child') {
            return null;
        }
        $aw = $this->audienceWords();
        $has = fn (string $k) => ! empty(array_intersect($foldedTokens, $aw[$k] ?? []));
        $male = $has('male');
        $female = $has('female') || $has('girl');
        if ($male xor $female) {
            return $male ? 'male' : 'female';
        }

        return null;
    }

    /**
     * Kategori satırı bu kitleye uygun mu? Yetişkin (male/female) sorgusunda çocuk
     * kategorileri ve karşı cinsiyetin kategorileri elenir; çocuk sorgusunda kelime-altküme
     * eşleşmesi zaten yeterli ("çocuk bot" ⊂ "Erkek Çocuk Bot").
     */
    protected function audienceAllows(array $rowWords, string $audience): bool
    {
        if ($audience === 'child') {
            return true;
        }
        $aw = $this->audienceWords();
        if (array_intersect($rowWords, array_merge($aw['child'] ?? [], $aw['girl'] ?? []))) {
            return false;
        }
        $opposite = $audience === 'male' ? 'female' : 'male';

        return empty(array_intersect($rowWords, $aw[$opposite] ?? []));
    }

    /**
     * İsim-OR için tip ifadeleri: kategoriye bağlanan (katlanmış) aralıktan kitle
     * kelimeleri çıkarılır, kalan ifade kategori eşanlamlılarıyla genişletilir.
     * ['erkek','sneaker'] → ['sneaker', 'spor ayakkabi']; ['erkek','cocuk'] → [].
     *
     * @return string[] katlanmış (deascii) ifadeler
     */
    protected function categorySpans(array $foldedSpan): array
    {
        $all = array_merge(...array_values($this->audienceWords() ?: [[]]));
        $type = array_values(array_filter($foldedSpan, fn ($w) => $w !== '' && ! in_array($w, $all, true)));
        if (empty($type)) {
            return [];
        }
        $phrase = implode(' ', $type);
        $out = [$phrase];
        // Eşanlamlı kapanışı (sınırlı tur): "spor ayakkabi" → +"sneaker"
        for ($round = 0; $round < 3; $round++) {
            $added = false;
            foreach ($out as $p) {
                foreach ($this->categoryAliases() as [$from, $to]) {
                    $fromP = implode(' ', $from);
                    $toP = implode(' ', $to);
                    if (preg_match('/(?<![a-z0-9])' . preg_quote($fromP, '/') . '(?![a-z0-9])/', $p)) {
                        $np = trim(preg_replace('/(?<![a-z0-9])' . preg_quote($fromP, '/') . '(?![a-z0-9])/', $toP, $p, 1));
                        if ($np !== '' && ! in_array($np, $out, true)) {
                            $out[] = $np;
                            $added = true;
                        }
                    }
                }
            }
            if (! $added) {
                break;
            }
        }

        return $out;
    }

    /**
     * Kategori eşanlamlıları: [[fromWords[], toWords[]], …] (katlanmış). Kaynak: config
     * search.turkish.category_synonyms + panel search_synonyms (type=synonym, active).
     * Yalnız kategori sözlüğünü genişletmede ve isim-OR ifadelerinde kullanılır.
     */
    protected function categoryAliases(): array
    {
        return Cache::remember("search:catalias:{$this->tenantId}", 3600, function () {
            $pairs = [];
            $push = function ($from, $to) use (&$pairs) {
                $f = $this->foldWords((string) $from);
                $t = $this->foldWords((string) $to);
                if ($f && $t && $f !== $t) {
                    $pairs[] = [$f, $t];
                }
            };
            foreach ((array) config('search.turkish.category_synonyms', []) as $term => $alts) {
                foreach ((array) $alts as $alt) {
                    $push($term, $alt);
                }
            }
            try {
                $rows = DB::table('search_synonyms')
                    ->where('tenant_id', $this->tenantId)
                    ->where('type', 'synonym')
                    ->where('status', 'active')
                    ->get(['term', 'synonyms']);
                foreach ($rows as $row) {
                    $alts = json_decode($row->synonyms ?? '[]', true);
                    foreach (is_array($alts) ? $alts : [] as $alt) {
                        $push($row->term, $alt);
                        $push($alt, $row->term); // panel girişi çift yönlü sayılır
                    }
                }
            } catch (\Throwable $e) {
                // tablo yok → yalnız config
            }

            return $pairs;
        });
    }

    /** "Spor Ayakkabı" → ['spor','ayakkabi'] (katlanmış kelime listesi). */
    protected function foldWords(string $phrase): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $this->trLower($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $w = $this->fold($w);
            if ($w !== '') {
                $words[] = $w;
            }
        }

        return $words;
    }

    /**
     * Kategori kelime-sözlüğünü eşanlamlılarla genişlet: "Kadın Spor Ayakkabı" satırına
     * ['kadin','sneaker'] ve ['bayan','spor','ayakkabi'] alternatifleri (aynı value) eklenir.
     * Böylece "sneaker", "erkek sneaker", "bayan spor ayakkabı" doğrudan kategoriye bağlanır.
     * Kapanış sınırlı turla (birleşik alternatifler: ['bayan','sneaker']).
     */
    protected function expandCategoryAliases(array $rows): array
    {
        $aliases = $this->categoryAliases();
        if (empty($aliases)) {
            return $rows;
        }
        $seen = [];
        foreach ($rows as $r) {
            $seen[$r['value'] . '|' . implode(' ', $r['words'])] = true;
        }
        for ($round = 0; $round < 3; $round++) {
            $added = [];
            foreach ($rows as $r) {
                foreach ($aliases as [$from, $to]) {
                    $pos = $this->subseqPos($r['words'], $from);
                    if ($pos === null) {
                        continue;
                    }
                    $words = array_merge(array_slice($r['words'], 0, $pos), $to, array_slice($r['words'], $pos + count($from)));
                    $key = $r['value'] . '|' . implode(' ', $words);
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $added[] = ['words' => $words, 'value' => $r['value']];
                    }
                }
            }
            if (empty($added)) {
                break;
            }
            $rows = array_merge($rows, $added);
        }

        return $rows;
    }

    /** $needle kelime dizisi $hay içinde ardışık geçiyorsa başlangıç indeksi. */
    protected function subseqPos(array $hay, array $needle): ?int
    {
        $n = count($needle);
        for ($i = 0; $i + $n <= count($hay); $i++) {
            if (array_slice($hay, $i, $n) === $needle) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Kısmi kelime eşleşmesi: tüketilmemiş bir token (≥3 harf), bir facet değerinin
     * önekiyse o facet'e bağlanır. En kısa (en bazik) değer önce → "siy"→Siyah ("Siyah Beyaz" değil).
     * Yalnız boş olan facet slotunu doldurur. As-you-type yazımı için kritik.
     */
    protected function prefixMatchFacets(array $foldedTokens, array &$consumed, array $vocab, array &$facets): void
    {
        $index = $this->prefixIndex($vocab);
        foreach ($foldedTokens as $i => $tok) {
            if ($consumed[$i] || mb_strlen($tok) < 3) {
                continue;
            }
            foreach ($index as $row) {
                // gerçek önek olmalı (eşit değil — eşit zaten exact eşleşmede tüketilirdi)
                if (! isset($facets[$row[2]]) && strlen($row[0]) > strlen($tok) && str_starts_with($row[0], $tok)) {
                    $facets[$row[2]] = $row[1];
                    $consumed[$i] = true;
                    break;
                }
            }
        }
    }

    /** Prefix eşleşme indeksi: [foldedNoSpace, OrijinalDeğer, facetKey], uzunluk artan (kısa=bazik önce).
     * Renk/kategori matchMulti'de zaten önek destekli — burada yalnız cinsiyet/marka. */
    protected function prefixIndex(array $vocab): array
    {
        $map = ['genders' => 'cinsiyet', 'brands' => 'brand'];
        $rows = [];
        foreach ($map as $vkey => $fkey) {
            foreach (($vocab[$vkey] ?? []) as $foldedPhrase => $value) {
                $rows[] = [str_replace(' ', '', $foldedPhrase), $value, $fkey];
            }
        }
        usort($rows, fn ($a, $b) => strlen($a[0]) <=> strlen($b[0]));

        return $rows;
    }

    /** Onaylı (active) yazım düzeltmelerini token'lara uygula: "snaker"→"sneaker" (facet'ten ÖNCE). */
    protected function applySpelling(array $tokens): array
    {
        $map = $this->spellingMap();
        if (empty($map)) {
            return $tokens;
        }

        return array_map(fn ($t) => $map[$this->fold($t)] ?? $t, $tokens);
    }

    /** ['foldedYanlış' => 'doğru'] — search_synonyms type=spelling, status=active. */
    protected function spellingMap(): array
    {
        return Cache::remember("search:spell:{$this->tenantId}", 3600, function () {
            $map = [];
            try {
                $rows = DB::table('search_synonyms')
                    ->where('tenant_id', $this->tenantId)
                    ->where('type', 'spelling')
                    ->where('status', 'active')
                    ->get(['term', 'synonyms']);
                foreach ($rows as $row) {
                    $alts = json_decode($row->synonyms ?? '[]', true);
                    if (is_array($alts) && ! empty($alts)) {
                        $map[$this->fold($row->term)] = (string) $alts[0];
                    }
                }
            } catch (\Throwable $e) {
                // tablo yok → düzeltme yok
            }

            return $map;
        });
    }

    /** Residual terimleri eşanlamlılarıyla genişlet (config tohumları + DB synonyms). */
    protected function expandSynonyms(array $terms): array
    {
        if (empty($terms)) {
            return [];
        }

        $map = $this->synonymMap();
        $out = $terms;
        foreach ($terms as $t) {
            $key = $this->fold($t);
            if (isset($map[$key])) {
                $out = array_merge($out, $map[$key]);
            }
        }

        return array_values(array_unique($out));
    }

    protected function intent(array $facets, array $terms): string
    {
        if (empty($terms)) {
            if (isset($facets['brand']) && count($facets) === 1) {
                return 'brand';
            }
            if (isset($facets['category'])) {
                return 'category';
            }
            if (! empty($facets)) {
                return 'facet';
            }
        }

        return 'product';
    }

    // ── Sözlük (tenant kataloğundan, cache'li) ──────────────────────────

    /**
     * Tenant'ın gerçek facet sözlüğü. Çok-kelimeli ifadeler uzunluk azalan sıralı
     * (en uzun-eşleşme için). 6 saat cache.
     */
    public function vocabulary(): array
    {
        // v2: color_words/category_words (kelime-bazlı çoklu eşleşme) eklendi
        // v3: category_words eşanlamlı alternatifleriyle genişletildi (sneaker↔spor ayakkabı)
        return Cache::remember("search:vocab3:{$this->tenantId}", 21600, function () {
            $colors = $this->distinctVariantAttr('Renk');
            $categories = $this->distinctProductColumn('category');

            return [
                'colors' => $this->phraseVocab($colors),
                'color_words' => $this->wordVocab($colors),
                'sizes' => $this->tokenVocab($this->distinctVariantAttr('Beden')),
                'genders' => $this->phraseVocab($this->distinctProductSpec('Cinsiyet')),
                'categories' => $this->phraseVocab($categories),
                'category_words' => $this->expandCategoryAliases($this->wordVocab($categories)),
                'brands' => $this->phraseVocab($this->distinctProductColumn('brand')),
            ];
        });
    }

    /**
     * Kelime listesi sözlüğü: değer, harf/rakam dışı ayraçlardan bölünüp fold edilir.
     * "Kadın Topuklu Bot&Çizme" → ['kadin','topuklu','bot','cizme'] (matchMulti için).
     */
    protected function wordVocab(array $values): array
    {
        $rows = [];
        foreach ($values as $v) {
            $words = [];
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $this->trLower($v), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                $w = $this->fold($w);
                if ($w !== '') {
                    $words[] = $w;
                }
            }
            if (! empty($words)) {
                $rows[] = ['words' => $words, 'value' => $v];
            }
        }

        return $rows;
    }

    /** ['foldedPhrase' => 'OrijinalDeğer'], kelime sayısı azalan sırada. */
    protected function phraseVocab(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $folded = trim(preg_replace('/\s+/', ' ', implode(' ', array_map(
                fn ($w) => $this->fold($w),
                preg_split('/\s+/u', $this->trLower($v), -1, PREG_SPLIT_NO_EMPTY) ?: []
            ))));
            if ($folded !== '') {
                $out[$folded] = $v;
            }
        }
        // En çok kelimeli (en spesifik) önce
        uksort($out, fn ($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' '));

        return $out;
    }

    /** ['foldedToken' => 'OrijinalDeğer'] (beden gibi tek-token değerler). */
    protected function tokenVocab(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $folded = $this->fold($v);
            if ($folded !== '') {
                $out[$folded] = $v;
            }
        }

        return $out;
    }

    protected function distinctVariantAttr(string $key): array
    {
        $k = $this->safeKey($key);

        return DB::table('product_variants')
            ->where('tenant_id', $this->tenantId)
            ->selectRaw("DISTINCT attributes->>'{$k}' as v")
            ->pluck('v')
            ->filter()
            ->values()
            ->all();
    }

    protected function distinctProductSpec(string $key): array
    {
        $k = $this->safeKey($key);

        return DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->selectRaw("DISTINCT specifications->>'{$k}' as v")
            ->pluck('v')
            ->filter()
            ->values()
            ->all();
    }

    protected function distinctProductColumn(string $column): array
    {
        return DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->filter()
            ->values()
            ->all();
    }

    /** jsonb anahtarını SQL'e gömerken güvenli tut (yalnız harf). */
    protected function safeKey(string $key): string
    {
        return preg_replace('/[^a-zA-ZçğıöşüÇĞİÖŞÜ]/u', '', $key) ?? '';
    }

    /** ['foldedTerm' => ['eşanlamlı',...]] — config tohumları + DB (active) synonyms. */
    protected function synonymMap(): array
    {
        return Cache::remember("search:syn:{$this->tenantId}", 3600, function () {
            $map = [];

            foreach ((array) config('search.turkish.color_synonyms', []) as $term => $alts) {
                $map[$this->fold((string) $term)] = array_values((array) $alts);
            }

            // search_synonyms tablosu henüz yoksa/erişilemezse config tohumlarıyla devam et
            // (arama, sinonim tablosuna HARD bağımlı olmamalı).
            try {
                $rows = DB::table('search_synonyms')
                    ->where('tenant_id', $this->tenantId)
                    ->where('status', 'active')
                    ->get(['term', 'synonyms']);
                foreach ($rows as $row) {
                    $alts = json_decode($row->synonyms ?? '[]', true);
                    if (is_array($alts) && $alts) {
                        $key = $this->fold($row->term);
                        $map[$key] = array_values(array_unique(array_merge($map[$key] ?? [], $alts)));
                    }
                }
            } catch (\Throwable $e) {
                // tablo yok → yalnız config tohumları
            }

            return $map;
        });
    }
}
