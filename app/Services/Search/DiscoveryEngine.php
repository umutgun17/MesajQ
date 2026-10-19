<?php

namespace App\Services\Search;

use App\Services\EmbeddingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Akıllı Arama — Keşif Motoru orkestratörü (TEK GİRİŞ NOKTASI).
 *
 *   QueryUnderstanding → (query embedding) → Retriever → Ranker → (Recommender fallback)
 *
 * search.js, panel, ve ileride e-posta/push/chatbot hepsi bu servisi çağırır.
 * Sorgu embedding'i tenant+normalized sorgu bazında cache'lenir (tekrar eden sorgu = 0 maliyet).
 */
class DiscoveryEngine
{
    protected QueryUnderstanding $understanding;

    protected Retriever $retriever;

    protected Ranker $ranker;

    protected Recommender $recommender;

    public function __construct(protected int $tenantId)
    {
        $this->understanding = new QueryUnderstanding($tenantId);
        $this->retriever = new Retriever($tenantId);
        $this->ranker = new Ranker();
        $this->recommender = new Recommender($tenantId);
    }

    public static function forTenant(int $tenantId): static
    {
        return new static($tenantId);
    }

    /**
     * Tam arama akışı. $context['offset'] ile sayfalama (widget sonsuz kaydırma):
     * aday havuzunun (candidate_limit) TAMAMI skorlanır + geçitten geçirilir,
     * sayfa dilimi offset'ten kesilir — sıralama deterministik (skor + id).
     *
     * @return array{query:string,understanding:array,results:array,recommendations:array,meta:array}
     */
    public function search(string $query, array $context = []): array
    {
        $start = microtime(true);
        $offset = max(0, (int) ($context['offset'] ?? 0));
        $resultLimit = (int) config('search.retrieval.result_limit', 24);
        $candidateLimit = (int) config('search.retrieval.candidate_limit', 200);

        $u = $this->understanding->parse($query);
        $u['_oos_direct'] = TenantSearchSettings::get($this->tenantId)['oos_direct'] ?? false; // Ranker: stoksuz en alta
        // Kullanıcının panelden seçtiği filtreler (kategori/marka/renk/beden/fiyat) sorgu
        // niyetinin ÜSTÜNE eklenir; aynı anahtarı EZER (daraltma çipi kategoriyi daraltır).
        $userFilters = $context['filters'] ?? [];
        $u = $this->applyFilters($u, $userFilters);

        // Kademeli gevşetme: çağıran (ör. chatbot) sorgudan türeyen bir faseti
        // bilerek düşürebilir ("38 kırmızı spor ayakkabı" stokta yoksa bedeni
        // düşürüp kırmızı spor ayakkabıları göster). Kullanıcının AÇIKÇA seçtiği
        // filtreler düşürülmez — onlar applyFilters'tan sonra tekrar yazılır.
        foreach ((array) ($context['drop_facets'] ?? []) as $dropKey) {
            $dropKey = (string) $dropKey;
            if (! array_key_exists($dropKey, $userFilters)) {
                unset($u['facets'][$dropKey], $u['facets_display'][$dropKey]);
            }
        }

        $vector = $this->queryVector($u);

        $candidates = $this->retriever->retrieve($u, $vector);
        $ranked = $this->ranker->rank($candidates, $u, count($candidates)); // hepsini skorla
        $gated = $this->applyRelevanceGate($ranked, $u);

        $total = count($gated);
        $results = array_slice($gated, $offset, $resultLimit);

        // Az/0 sonuçta öneri fallback'i (boş bırakma) — yalnız İLK sayfada; gösterilen
        // sonuçlar önerilerde TEKRAR çıkmasın (dublike kart)
        $recommendations = [];
        $skipAt = (int) config('search.recommendations.skip_when_results_gte', 6);
        if ($offset === 0 && config('search.recommendations.enabled', true) && $total < $skipAt) {
            $recommendations = $this->recommender->forQuery(
                $u,
                $vector,
                (int) config('search.recommendations.limit', 12),
                $results // dublike engeli + bağlam anchor'ı (kategori/semantik kuyruk)
            );
        }

        return [
            'query' => $u['raw'],
            'understanding' => [
                'normalized' => $u['normalized'],
                'facets' => $u['facets'],
                'facets_display' => $u['facets_display'] ?? null,
                'terms' => $u['terms'],
                'intent' => $u['intent'],
            ],
            'results' => $results,
            'recommendations' => $recommendations,
            // Faset toplama (filtre paneli + daraltma çipleri) — yalnız ilk sayfada,
            // sayfalamada tekrar hesaplanmaz. Aktif filtreler yansıtılır.
            'facets' => $offset === 0 ? $this->computeFacets($gated, $u) : null,
            // Yalnız kullanıcının açıkça seçtiği filtreler (kaldırılabilir çipler); sorgudan
            // türeyen facet'ler (ör. "bot"→kategoriler) buraya girmez.
            'applied_filters' => $userFilters,
            'meta' => [
                'total' => $total, // geçitten geçen GERÇEK eşleşme sayısı (havuz sınırına kadar)
                'offset' => $offset,
                'returned' => count($results),
                'has_more' => $offset + count($results) < $total,
                // true → aday havuzu doldu (candidate_limit); gerçek toplam daha da fazla
                // olabilir (widget "200+ sonuç" gösterir)
                'capped' => count($candidates) >= $candidateLimit,
                'vector_used' => $vector !== null,
                'recommendations_shown' => count($recommendations) > 0,
                'took_ms' => (int) round((microtime(true) - $start) * 1000),
            ],
        ];
    }

    /**
     * Panel filtrelerini sorgu niyetine uygula. Kullanıcı bir facet'i açıkça seçtiyse
     * (kategori/marka/renk/beden) o anahtarı EZER; fiyat aralığı ayrı anahtarlara yazılır.
     * Kategori açıkça seçilince category_span sıfırlanır (isim-OR genişletmesi kapanır →
     * kullanıcı "Kadın Bot"a daralttıysa isim eşleşmesiyle geri genişlemesin).
     */
    protected function applyFilters(array $u, array $filters): array
    {
        if (empty($filters)) {
            return $u;
        }
        foreach (['category', 'brand', 'renk', 'beden'] as $k) {
            $v = $filters[$k] ?? null;
            $v = is_array($v) ? array_values(array_filter(array_map('strval', $v), fn ($x) => $x !== '')) : [];
            if (! empty($v)) {
                $u['facets'][$k] = count($v) === 1 ? $v[0] : $v;
                if ($k === 'category') {
                    $u['category_span'] = null;
                    $u['category_spans'] = [];
                    $u['facets_display']['category'] = count($v) === 1 ? $v[0] : ($v[0] . ' +' . (count($v) - 1));
                }
            }
        }
        // Fiyat aralığı (retrieve okur)
        foreach (['price_min', 'price_max'] as $k) {
            if (isset($filters[$k]) && is_numeric($filters[$k])) {
                $u['facets'][$k] = (float) $filters[$k];
            }
        }
        $u['intent'] = 'facet';

        return $u;
    }

    /**
     * Faset toplama: sonuç havuzundan kategori/marka/fiyat (bellek içi, bedava) + renk/beden
     * (tek gruplu varyant sorgusu) sayımları. Filtre paneli + daraltma çipleri bunu kullanır.
     * Havuz candidate_limit ile sınırlı olduğundan sayımlar "≥" yaklaşıktır (total gibi).
     */
    protected function computeFacets(array $gated, array $u): array
    {
        $cat = [];
        $brand = [];
        $pmin = null;
        $pmax = null;
        $ids = [];
        foreach ($gated as $r) {
            $ids[] = (int) ($r['id'] ?? 0);
            $c = trim((string) ($r['category'] ?? ''));
            if ($c !== '') {
                $cat[$c] = ($cat[$c] ?? 0) + 1;
            }
            $b = trim((string) ($r['brand'] ?? ''));
            if ($b !== '') {
                $brand[$b] = ($brand[$b] ?? 0) + 1;
            }
            $dp = (float) ($r['discounted_price'] ?? 0);
            $pr = (float) ($r['price'] ?? 0);
            $eff = ($dp > 0 && $dp < $pr) ? $dp : ($pr > 0 ? $pr : $dp);
            if ($eff > 0) {
                $pmin = $pmin === null ? $eff : min($pmin, $eff);
                $pmax = $pmax === null ? $eff : max($pmax, $eff);
            }
        }
        arsort($cat);
        arsort($brand);

        // Renk/Beden — tek gruplu sorgu (yalnız STOKTA varyant)
        $colors = [];
        $sizes = [];
        $ids = array_values(array_unique(array_filter($ids)));
        if (! empty($ids)) {
            $rows = DB::table('product_variants')
                ->where('tenant_id', $this->tenantId)
                ->whereIn('product_id', $ids)
                ->whereRaw('coalesce(stock_quantity, 0) > 0')
                ->selectRaw("attributes->>'Renk' as renk, attributes->>'Beden' as beden, count(distinct product_id) as c")
                ->groupByRaw("attributes->>'Renk', attributes->>'Beden'")
                ->get();
            foreach ($rows as $row) {
                $r = trim((string) $row->renk);
                if ($r !== '') {
                    $colors[$r] = ($colors[$r] ?? 0) + (int) $row->c;
                }
                $b = trim((string) $row->beden);
                if ($b !== '') {
                    $sizes[$b] = ($sizes[$b] ?? 0) + (int) $row->c;
                }
            }
            arsort($colors);
            uksort($sizes, fn ($a, $b) => $this->sizeSort($a) <=> $this->sizeSort($b));
        }

        $pack = fn ($arr, $limit) => array_map(
            fn ($k, $v) => ['value' => (string) $k, 'count' => (int) $v],
            array_slice(array_keys($arr), 0, $limit),
            array_slice(array_values($arr), 0, $limit)
        );

        return [
            'categories' => $pack($cat, 14),
            'brands' => $pack($brand, 10),
            'colors' => $pack($colors, 16),
            'sizes' => $pack($sizes, 24),
            'price' => ($pmin !== null) ? ['min' => floor($pmin), 'max' => ceil($pmax)] : null,
        ];
    }

    /** Beden sıralaması: sayısal bedenler değerine, harfli (S/M/L/STD) sona alfabetik. */
    protected function sizeSort(string $s): float
    {
        return is_numeric($s) ? (float) $s : 9000 + (float) crc32($s) / 1e9;
    }

    /**
     * Alaka geçidi: serbest terim VARSA sonuçlar semantic/lexical eşiğini geçmeli —
     * facet olsa bile ("kırmızı dağcılık 37"nin terimi tutmayan kırmızı 37'leri
     * "sonuç" diye sayması yerine öneriye düşür). Saf facet sorgusu (terim yok) geçer.
     */
    protected function applyRelevanceGate(array $results, array $u): array
    {
        if (empty($u['terms'])) {
            return $results;
        }

        $minSem = (float) config('search.ranking.min_semantic', 0.42);
        $minLex = (float) config('search.ranking.min_lexical', 0.15);

        return array_values(array_filter($results, function ($r) use ($minSem, $minLex) {
            // Ürün kodu tam eşleşmesi geçitten muaf (kod yalnız SKU'da geçiyorsa
            // isim benzerliği düşük kalabilir — yine de kesin sonuçtur)
            if (($r['_code_hit'] ?? 0) >= 1) {
                return true;
            }
            $sem = (float) ($r['_signals']['semantic'] ?? 0);
            $lex = (float) ($r['_signals']['lexical'] ?? 0);

            return $sem >= $minSem || $lex >= $minLex;
        }));
    }

    /** Otomatik tamamlama (hafif: yalnız retrieval, öneri/embed yok). */
    public function autocomplete(string $query): array
    {
        $u = $this->understanding->parse($query);
        $u['_oos_direct'] = TenantSearchSettings::get($this->tenantId)['oos_direct'] ?? false; // Ranker: stoksuz en alta
        $limit = (int) config('search.retrieval.autocomplete_limit', 8);
        $candidates = $this->retriever->retrieve($u, null, $limit * 3);

        if (! $u['_oos_direct']) {
            return array_slice($this->ranker->rank($candidates, $u), 0, $limit);
        }

        // oos_direct: kod yazıldıysa yalnız kodu tutanlar; doğrudan eşleşen tükenmiş ürünler
        // (Retriever yalnız onları geçirir) kısa listede kesilmesin — en alta, en çok 3 tane.
        $ranked = $this->ranker->rank($candidates, $u, count($candidates));
        if (array_filter($ranked, fn ($r) => $r['_code_hit'] >= 1)) {
            $ranked = array_values(array_filter($ranked, fn ($r) => $r['_code_hit'] >= 1));
        }
        $oos = array_slice(array_values(array_filter($ranked, fn ($r) => ! $r['_in_stock'])), 0, 3);
        $in = array_values(array_filter($ranked, fn ($r) => $r['_in_stock']));

        return array_merge(array_slice($in, 0, $limit - count($oos)), $oos);
    }

    /**
     * Sorgu embedding'i — facet değerleri + serbest terimlerden temiz semantik metin kurar
     * (saf beden sayısı semantik taşımaz, atlanır). Tenant+metin bazında cache'lenir.
     *
     * @return float[]|null
     */
    protected function queryVector(array $u): ?array
    {
        if (! config('search.retrieval.vector_enabled', true)) {
            return null;
        }

        // Hız: saf facet sorgusunda (serbest terim yok) semantic katkısı düşük — embedding'i
        // (OpenAI çağrısı) atla. "kırmızı 44", "siyah spor ayakkabı" anında döner.
        if (empty($u['terms'])) {
            return null;
        }

        $text = $this->embeddingText($u);
        if ($text === '') {
            return null;
        }

        $key = "search:qvec:{$this->tenantId}:" . md5($text);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $vector = EmbeddingService::forTenant($this->tenantId)->embed($text);
        if (is_array($vector)) {
            $ttl = (int) config('search.understanding.cache_ttl_minutes', 1440);
            Cache::put($key, $vector, now()->addMinutes($ttl));

            return $vector;
        }

        return null;
    }

    /** Embedding metni: renk + cinsiyet + kategori + marka + serbest terimler (beden hariç).
     * Çoklu facet değerinde ilk (en yalın) değer alınır; terimlerde sinonim genişletmesi dahil. */
    protected function embeddingText(array $u): string
    {
        $facets = $u['facets'] ?? [];
        $parts = [];
        foreach (['renk', 'cinsiyet', 'category', 'brand'] as $k) {
            $v = $facets[$k] ?? null;
            if (is_array($v)) {
                $v = $v[0] ?? null;
            }
            if ($v !== null && $v !== '') {
                $parts[] = (string) $v;
            }
        }
        $parts = array_merge($parts, array_slice($u['expanded_terms'] ?? $u['terms'] ?? [], 0, 6));
        $text = trim(implode(' ', $parts));

        return $text !== '' ? $text : (string) ($u['normalized'] ?? '');
    }
}
