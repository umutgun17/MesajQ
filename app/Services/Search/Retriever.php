<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * Akıllı Arama — hibrit aday getirme (retrieval).
 *
 * Üç sinyali birleştirir:
 *   - Facet filtre: category/brand/cinsiyet (products) + renk/beden (product_variants EXISTS)
 *   - Lexical: pg_trgm similarity(name, terms)  (idx_products_name_trgm)
 *   - Semantic: pgvector cosine  embedding <=> qvec  (idx_products_embedding, HNSW)
 *
 * Ranking'e girecek aday havuzunu (ham sinyallerle) döndürür; nihai skor [[Ranker]]'da.
 */
class Retriever
{
    public function __construct(protected int $tenantId)
    {
    }

    public static function forTenant(int $tenantId): static
    {
        return new static($tenantId);
    }

    /**
     * @param  array  $u  QueryUnderstanding::parse() çıktısı
     * @param  float[]|null  $vector  Sorgu embedding'i (semantic için); yoksa facet+lexical
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(array $u, ?array $vector = null, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('search.retrieval.candidate_limit', 200);
        $facets = $u['facets'] ?? [];
        $terms = $u['terms'] ?? [];
        // Lexical eşleşmede sinonim genişletmesi de kullanılır ("tisört"→"tişört")
        $lexTerms = array_slice(array_values(array_unique(array_filter(
            array_map('trim', $u['expanded_terms'] ?? $terms),
            fn ($t) => $t !== ''
        ))), 0, 6);
        $hasLex = ! empty($lexTerms);

        $q = DB::table('products as p')
            ->where('p.tenant_id', $this->tenantId)
            ->where('p.is_active', true);

        $ts = TenantSearchSettings::get($this->tenantId); // panelden yönetilir

        // Türkçe katlama (deascii) — lexical ve isim-OR karşılaştırmalarında iki taraf da katlanır
        $trFold = "translate(p.name, 'ıİIşŞçÇğĞöÖüÜâÂîÎûÛéÉ', 'iiissccggoouuaaiiuuee')";

        // Tükenen ürünü hiç gösterme (satın almaya yönlendir) — istisna (oos_direct ayarı):
        // müşteri belli bir ürünü stok kodu ya da adıyla arıyorsa tükenmişi de gelir; Ranker en alta koyar.
        $direct = null;
        if ($ts['require_stock']) {
            $direct = ($ts['oos_direct'] ?? false) ? $this->directMatchSql($u, $terms, $trFold) : null;
            if ($direct !== null) {
                $q->whereRaw('(coalesce(p.stock_quantity, 0) > 0 or ' . $direct[0] . ')', $direct[1]);
            } else {
                $q->whereRaw('coalesce(p.stock_quantity, 0) > 0');
            }
        }

        // Sitede sayfası olmayan ürün (url NULL — Ticimax'ta ürün kartı yayında değil)
        // aramada gösterilmez: tıklanamayan sonuç 404'ten farksız.
        $q->whereRaw("coalesce(p.url, '') <> ''");

        // Görseli olmayan ürün de gösterilmez (placeholder kart vitrini çirkinleştiriyor)
        if ($ts['require_image']) {
            $q->whereRaw("jsonb_typeof(p.images) = 'array' and jsonb_array_length(p.images) > 0");
        }

        // ── Facet filtreleri (tek değer veya çoklu değer listesi) ──
        if (! empty($facets['category'])) {
            // Kategori filtresi, kullanıcının yazdığı ifadenin İSİM eşleşmesiyle OR'lanır:
            // katalogda kategorisi boş/yanlış ama adı "…Valiz…" olan ürünler de gelsin
            // ("valiz" 88 ürünün 72'sini kaçırıyordu). \m = PG kelime başı (önek anlamı,
            // matchMulti'nin önek mantığıyla tutarlı).
            // İsim-OR ifadeleri: TİP ifadeleri (kitle kelimesi çıkarılmış, eşanlamlı
            // genişletilmiş, katlanmış: "erkek sneaker" → sneaker | spor ayakkabi) —
            // kitle kısıtı isim üzerinde ayrıca uygulanır: erkek → (erkek|unisex) ve çocuk
            // değil; kadın → (kadin|bayan|unisex) ve çocuk değil; çocuk → çocuk/bebek/kız.
            // Kategorisi BOŞ 355 satılabilir ürün (TBZER020 gibi en çok satanlar) yalnız
            // bu yoldan sonuca girer.
            $spans = $u['category_spans'] ?? null;
            if ($spans === null) { // eski çağıran (yalnız category_span)
                $s = trim((string) ($u['category_span'] ?? ''));
                $spans = $s !== '' ? [$this->foldForTrgm($s)] : [];
            }
            $spans = array_values(array_filter(array_map('trim', (array) $spans), fn ($s) => mb_strlen($s) >= 3));
            $cats = (array) $facets['category'];
            $audience = $u['audience'] ?? null;
            $audienceSub = $u['audience_sub'] ?? null;
            if (! empty($spans)) {
                $catFold = "translate(coalesce(p.category, ''), 'ıİIşŞçÇğĞöÖüÜâÂîÎûÛéÉ', 'iiissccggoouuaaiiuuee')";
                $q->where(function ($w) use ($cats, $spans, $audience, $audienceSub, $trFold, $catFold) {
                    $w->whereIn('p.category', $cats)
                        ->orWhere(function ($n) use ($spans, $audience, $audienceSub, $trFold, $catFold) {
                            $n->where(function ($s) use ($spans, $trFold) {
                                foreach ($spans as $sp) {
                                    $s->orWhereRaw("{$trFold} ~* ('\\m' || ?)", [preg_quote($sp)]);
                                }
                            });
                            $rx = $this->audienceNameRegex($audience, $audienceSub);
                            if ($rx !== null) {
                                $n->whereRaw("{$trFold} ~* ?", [$rx['must']]);
                                if ($rx['not'] !== null) {
                                    $n->whereRaw("{$trFold} !~* ?", [$rx['not']]);
                                }
                                // Karşı kitlenin kategorisine konmuş ürün isim yoluyla sızmasın:
                                // "kadın spor ayakkabı"ya "Erkek Spor Ayakkabı"daki unisex model,
                                // "erkek çocuk"a "Kız Çocuk" kategorisi girmez (kategorisi boş/
                                // cinsiyetsiz olanlar girer — TBZER020 bu yoldan gelir).
                                if ($rx['cat_not'] !== null) {
                                    $n->whereRaw("{$catFold} !~* ?", [$rx['cat_not']]);
                                }
                            }
                        });
                });
            } else {
                $q->whereIn('p.category', $cats);
            }
        }
        if (! empty($facets['brand'])) {
            $q->whereIn('p.brand', (array) $facets['brand']);
        }
        if (! empty($facets['cinsiyet'])) {
            $q->whereIn(DB::raw("p.specifications->>'Cinsiyet'"), (array) $facets['cinsiyet']);
        }

        // Fiyat aralığı (panel filtresi) — indirimliyse indirimli, değilse liste fiyatı
        if (isset($facets['price_min']) || isset($facets['price_max'])) {
            $effPrice = "case when coalesce(p.discounted_price,0) > 0 and p.discounted_price < p.price then p.discounted_price else p.price end";
            if (isset($facets['price_min'])) {
                $q->whereRaw("$effPrice >= ?", [(float) $facets['price_min']]);
            }
            if (isset($facets['price_max'])) {
                $q->whereRaw("$effPrice <= ?", [(float) $facets['price_max']]);
            }
        }

        // Renk/Beden varyant düzeyinde — AYNI varyantta eşleşmeli (kırmızı VE 44 olan beden).
        // Varsayılan: aranan varyant STOKTA da olmalı ("36" arayana 36'sı tükenmiş ürün
        // gösterilmez; az sonuç kalırsa öneri fallback'i devreye girer).
        $renk = array_values((array) ($facets['renk'] ?? []));
        $beden = array_values((array) ($facets['beden'] ?? []));
        if ($renk || $beden) {
            $requireStock = $ts['require_variant_stock'];
            $q->whereExists(function ($sub) use ($renk, $beden, $requireStock) {
                $sub->select(DB::raw('1'))
                    ->from('product_variants as v')
                    ->whereColumn('v.product_id', 'p.id');
                if ($requireStock) {
                    $sub->whereRaw('coalesce(v.stock_quantity, 0) > 0');
                }
                if ($renk) {
                    $sub->whereIn(DB::raw("v.attributes->>'Renk'"), $renk);
                }
                if ($beden) {
                    $sub->whereIn(DB::raw("v.attributes->>'Beden'"), $beden);
                }
            });
        }

        // ── Select: görünüm alanları + ham sinyaller ──
        $q->select([
            'p.id', 'p.name', 'p.brand', 'p.category', 'p.price', 'p.discounted_price',
            'p.stock_quantity', 'p.images', 'p.url', 'p.performance_score', 'p.metadata',
        ]);

        // Aranan renk/beden varyantı STOKTA mı? (ürün toplam stoğu değil — "kırmızı 44"
        // arayana 44'ü tükenmiş ürünü "stokta" diye gösterme; Ranker + rozet bunu kullanır)
        if ($renk || $beden) {
            $conds = ['v2.product_id = p.id', 'coalesce(v2.stock_quantity, 0) > 0'];
            $binds = [];
            if ($renk) {
                $conds[] = "v2.attributes->>'Renk' in (" . implode(',', array_fill(0, count($renk), '?')) . ')';
                $binds = array_merge($binds, $renk);
            }
            if ($beden) {
                $conds[] = "v2.attributes->>'Beden' in (" . implode(',', array_fill(0, count($beden), '?')) . ')';
                $binds = array_merge($binds, $beden);
            }
            $q->selectRaw('exists(select 1 from product_variants v2 where ' . implode(' and ', $conds) . ') as facet_in_stock', $binds);
        } else {
            $q->selectRaw('NULL::boolean as facet_in_stock');
        }

        // Lexical (pg_trgm word_similarity) — terim, ismin İÇİNDEKİ en benzer kelimeyle
        // kıyaslanır: similarity() uzun isimlerde kısa sorguyu boğuyordu ("rahat" →
        // "Rahat Kalıp Poli Taban…" ≈0.12), word_similarity aynı çift için ≈1.0 verir.
        // İki taraf da Türkçe-katlanır (deascii): pg_trgm'in locale lower'ı 'Işıklı'yı
        // 'işıklı' yapar (noktalı i), sorgudaki 'ışık' ile ilk trigramlar tutmaz →
        // "ışık" 0.2'de kalıp gate'e takılıyordu; katlanınca 0.8.
        $folded = array_map(fn ($t) => $this->foldForTrgm($t), $lexTerms);
        if ($hasLex) {
            $parts = [];
            $binds = [];
            foreach ($folded as $t) {
                $parts[] = "word_similarity(?, {$trFold})";
                $binds[] = $t;
            }
            // Malzeme/taş sözlüğü (tenant ayarı; boşsa hiç devreye girmez): sorgudaki kelime sözlükteyse
            // ürün AÇIKLAMASINDA tam kelime olarak aranır ve ad eşleşmesi gibi 1.0 sayılır
            // ("ametist" → "İnci, ametist, lepidolit kullanılmıştır" açıklamalı 20 ürün; adında yalnız 1'i geçer).
            $materials = array_map(fn ($m) => $this->foldForTrgm($m), (array) ($ts['material_terms'] ?? []));
            if ($materials) {
                $descFold = "translate(lower(coalesce(p.description, '')), 'ıİIşŞçÇğĞöÖüÜâÂîÎûÛéÉ', 'iiissccggoouuaaiiuuee')";
                foreach ($folded as $t) {
                    $isMaterial = false;
                    foreach ($materials as $m) {
                        if ($t === $m || preg_match('/(^|\s)' . preg_quote($t, '/') . '($|\s)/u', $m)) {
                            $isMaterial = true;
                            break;
                        }
                    }
                    if ($isMaterial && mb_strlen($t) >= 3) {
                        $parts[] = "(case when {$descFold} ~* ('\\m' || ?) then 1.0 else 0.0 end)";
                        $binds[] = preg_quote($t);
                        // Taş/malzeme yazan müşteri onu istiyor: adında ya da açıklamasında geçmeyen ürün sonuca girmez
                        // (popüler ama ilgisiz ürünler semantik/performans puanıyla öne geçmesin)
                        $q->whereRaw("({$trFold} ~* ('\\m' || ?) or {$descFold} ~* ('\\m' || ?))", [preg_quote($t), preg_quote($t)]);
                    }
                }
            }
            $q->selectRaw('greatest(' . implode(', ', $parts) . ') as name_sim', $binds);
        } else {
            $q->selectRaw('0.0 as name_sim');
        }

        // Tam-kelime eşleşmesi: sorgu terimi isimde BAĞIMSIZ kelime olarak geçiyorsa
        // ("kedi" ⊂ "Köpek ve Kedi Taşıma Çantası"). Typo bandı (min_lexical 0.34)
        // trigram-yakın kelimeleri de sonuç sayar (kedi≈kemer 0.40) ve popülerlik
        // ağırlıkları onları öne geçirebilir — Ranker'da bu sinyal code_hit'ten sonraki
        // sıralama kademesidir: birebir kelime içeren ürünler bulanık eşleşmelerin önünde.
        // Çok kelimeli sorguda ("Magic Heart") kaç terimin isimde birebir geçtiği sayılır:
        // tüm kelimeleri içeren ürün, yalnız "heart" tutan kardeşlerin (Ocean Heart) önünde.
        if ($hasLex) {
            $conds = [];
            $binds = [];
            foreach ($folded as $t) {
                $conds[] = "(case when {$trFold} ~* ('\\m' || ? || '\\M') then 1 else 0 end)";
                $binds[] = preg_quote($t);
            }
            $q->selectRaw('(' . implode(' + ', $conds) . ') as exact_hit', $binds);
        } else {
            $q->selectRaw('0 as exact_hit');
        }

        // Ürün kodu tam eşleşmesi (TBARZ1015 gibi harf+rakam token): isimde kelime-başı
        // eşleşme VEYA SKU içinde geçme. Trigram/embedding kardeş kodları (TBARZ1010)
        // ayırt edemediğinden bu sinyal Ranker'da her şeyin önüne geçer.
        // 2 = SKU/varyant SKU birebir (kesin ürün), 1 = isimde kod öneki / SKU içinde geçme
        // (40108 → 140108'in önüne birebir olan geçer). Varyant SKU'ları da aranır (110211-12).
        $codes = array_slice(array_values((array) ($u['code_terms'] ?? [])), 0, 3);
        if ($codes) {
            $exact = [];
            $partial = [];
            $binds = [];
            foreach ($codes as $c) {
                $exact[] = 'lower(p.sku) = lower(?)';
                $binds[] = $c;
                $exact[] = 'exists(select 1 from product_variants vc where vc.product_id = p.id and lower(vc.sku) = lower(?))';
                $binds[] = $c;
            }
            foreach ($codes as $c) {
                $like = '%' . addcslashes($c, '%_\\') . '%';
                $partial[] = "p.name ~* ('\\m' || ?)";
                $binds[] = preg_quote($c);
                $partial[] = 'p.sku ilike ?';
                $binds[] = $like;
                $partial[] = 'exists(select 1 from product_variants vc where vc.product_id = p.id and vc.sku ilike ?)';
                $binds[] = $like;
            }
            $q->selectRaw('(case when ' . implode(' or ', $exact) . ' then 2 when ' . implode(' or ', $partial) . ' then 1 else 0 end) as code_hit', $binds);
        } else {
            $q->selectRaw('0 as code_hit');
        }

        // Semantic (pgvector) — sorgu vektörü varsa kosinüs mesafesi
        if ($vector !== null && config('search.retrieval.vector_enabled', true)) {
            $vecLit = '[' . implode(',', array_map(static fn ($x) => (float) $x, $vector)) . ']';
            $q->whereNotNull('p.embedding')
                ->selectRaw('(p.embedding <=> ?::vector) as distance', [$vecLit]);
        } else {
            $q->selectRaw('NULL::float as distance');
        }

        // GA4 davranış sinyali (korelasyonlu alt sorgu — join çoğaltması yok)
        $q->selectRaw('(select coalesce(sum(g.purchases),0) from product_ga4_cache g where g.product_id = p.id) as ga4_purchases');
        $q->selectRaw('(select coalesce(sum(g.views),0) from product_ga4_cache g where g.product_id = p.id) as ga4_views');

        // ── Sıralama: kod eşleşmesi > semantic > lexical > performans (+id: determinizm) ──
        // code_hit önce: aday havuzu (candidate_limit) dolarsa tam kod eşleşmesi dışarıda kalmasın
        $useVector = $vector !== null && config('search.retrieval.vector_enabled', true);
        $primary = clone $q;
        if ($codes) {
            $primary->orderByRaw('code_hit desc');
        }
        if ($useVector) {
            $primary->orderByRaw('distance asc');
        } elseif ($hasLex) {
            $primary->orderByRaw('name_sim desc');
        } else {
            $primary->orderByRaw('p.performance_score desc nulls last');
        }
        $primary->orderBy('p.id');

        $rows = $primary->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        // Vektör havuzu embedding'e kör kalabilir: ascii-typo sorguda ("isik") sorgu
        // vektörü çöp olur ve lexical'ı 0.8+ olacak ürünler mesafe-top-K'ya hiç giremez.
        // Trigram-en-iyileri UNION'la havuza ekle (id dedup; yalnız min_lexical'ı geçenler —
        // gate eşiğinin altındakiler zaten elenirdi, havuzu şişirmesin).
        if ($useVector && $hasLex) {
            $minLex = (float) config('search.ranking.min_lexical', 0.34);
            $lexQ = clone $q;
            if ($codes) {
                $lexQ->orderByRaw('code_hit desc');
            }
            $lexQ->orderByRaw('name_sim desc')->orderBy('p.id');
            $lexLimit = (int) config('search.retrieval.lexical_union_limit', 100);
            $seen = array_flip(array_column($rows, 'id'));
            foreach ($lexQ->limit($lexLimit)->get() as $r) {
                $r = (array) $r;
                if (! isset($seen[$r['id']]) && (float) $r['name_sim'] >= $minLex) {
                    $rows[] = $r;
                }
            }
        }

        // Doğrudan arama eşleşmeleri (kod / birebir ad — az sayıda) havuz sınırına takılmasın:
        // "Danae Steel Earring" yüzlerce "steel earring" arasında top-K'ya giremiyordu.
        if ($direct !== null) {
            $seen = array_flip(array_column($rows, 'id'));
            $dq = clone $q;
            foreach ($dq->whereRaw($direct[0], $direct[1])->orderBy('p.id')->limit(50)->get() as $r) {
                $r = (array) $r;
                if (! isset($seen[$r['id']])) {
                    $rows[] = $r;
                }
            }
        }

        return $rows;
    }

    /**
     * "Doğrudan arama" koşulu [sql, binds] — stoksuz ürünün sonuca girebilmesi için (oos_direct):
     *   - ürün kodu: SKU / varyant SKU içinde geçer ya da isim kodla başlar (code_hit ≥ 1 ile aynı)
     *   - ürün adı: serbest terimlerin TÜMÜ isimde bağımsız kelime ve bu ad az ürünü tarif ediyor
     *     (≤ oos_direct_name_max; "kolye" gibi genel kelime yüzlerce ürüne uyar → doğrudan arama sayılmaz)
     * Hiçbiri yoksa null (stok filtresi aynen kalır).
     */
    protected function directMatchSql(array $u, array $terms, string $trFold): ?array
    {
        $ors = [];
        $binds = [];
        foreach (array_slice(array_values((array) ($u['code_terms'] ?? [])), 0, 3) as $c) {
            $like = '%' . addcslashes($c, '%_\\') . '%';
            $ors[] = "p.name ~* ('\\m' || ?)";
            $binds[] = preg_quote($c);
            $ors[] = 'p.sku ilike ?';
            $binds[] = $like;
            $ors[] = 'exists(select 1 from product_variants vd where vd.product_id = p.id and vd.sku ilike ?)';
            $binds[] = $like;
        }

        $words = array_values(array_unique(array_filter(
            array_map(fn ($t) => $this->foldForTrgm(trim((string) $t)), array_slice($terms, 0, 6)),
            fn ($t) => mb_strlen($t) >= 3
        )));
        if ($words) {
            $conds = [];
            $nb = [];
            foreach ($words as $t) {
                $conds[] = "{$trFold} ~* ('\\m' || ? || '\\M')";
                $nb[] = preg_quote($t);
            }
            $nameSql = '(' . implode(' and ', $conds) . ')';
            $count = (int) DB::table('products as p')
                ->where('p.tenant_id', $this->tenantId)
                ->where('p.is_active', true)
                ->whereRaw("coalesce(p.url, '') <> ''")
                ->whereRaw($nameSql, $nb)
                ->count();
            if ($count > 0 && $count <= (int) config('search.retrieval.oos_direct_name_max', 20)) {
                $ors[] = $nameSql;
                $binds = array_merge($binds, $nb);
            }
        }

        return $ors ? ['(' . implode(' or ', $ors) . ')', $binds] : null;
    }

    /**
     * Kitle isim-regex'i (katlanmış isim üzerinde): ['must' => …, 'not' => …|null].
     * Unisex adlı ürün yetişkin iki kitleye de girer; çocuk kelimesi geçen ürün yetişkin
     * kitleden düşer. Kelime listeleri config search.turkish.audience'tan (katlanmış).
     */
    protected function audienceNameRegex(?string $audience, ?string $sub = null): ?array
    {
        if ($audience === null) {
            return null;
        }
        $cfg = (array) config('search.turkish.audience', []);
        $fold = fn (array $lists) => array_values(array_unique(array_filter(array_map(
            fn ($w) => preg_quote($this->foldForTrgm((string) $w)),
            array_merge(...array_map(fn ($k) => (array) ($cfg[$k] ?? []), $lists))
        ))));
        $rx = fn (array $words) => $words ? '\\m(' . implode('|', $words) . ')\\M' : null;

        $childRx = $rx($fold(['child', 'girl']));
        if ($audience === 'child') {
            // alt-cinsiyet: "erkek çocuk" → adında kız/kadın geçen ve Kız kategorisindeki düşer
            $not = $sub === 'male' ? $rx($fold(['girl', 'female'])) : ($sub === 'female' ? $rx($fold(['male'])) : null);

            return $childRx ? ['must' => $childRx, 'not' => $not, 'cat_not' => $not] : null;
        }
        $own = $fold([$audience]);
        $own[] = 'unisex';
        $opposite = $audience === 'male' ? 'female' : 'male';

        return [
            'must' => $rx(array_unique($own)),
            'not' => $childRx,
            'cat_not' => $rx($fold([$opposite, 'child', 'girl'])),
        ];
    }

    /**
     * Trigram kıyası için Türkçe katlama — SQL tarafındaki translate() ile birebir aynı
     * harita (ı/İ→i, ş→s…); boşluk/rakam korunur (QueryUnderstanding::fold'dan farkı bu:
     * o, a-z0-9 dışını siler — çok-kelimeli sinonim terimlerini bozardı).
     */
    protected function foldForTrgm(string $t): string
    {
        return mb_strtolower(strtr($t, [
            'ı' => 'i', 'İ' => 'i', 'I' => 'i',
            'ş' => 's', 'Ş' => 's', 'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g', 'ö' => 'o', 'Ö' => 'o',
            'ü' => 'u', 'Ü' => 'u',
            'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i',
            'û' => 'u', 'Û' => 'u', 'é' => 'e', 'É' => 'e',
        ]), 'UTF-8');
    }
}
