<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * Akıllı Arama — öneri motoru (PAYLAŞIMLI servis).
 *
 * Search'in zero/low-result fallback'i için forQuery(); ayrıca PDP/e-posta/push/chatbot'un
 * yeniden kullanacağı item-item primitifleri: similarToProduct (pgvector), boughtTogether
 * (order_items co-purchase), popular (performance_score). "Tek motor, çok kanal" kozu.
 */
class Recommender
{
    protected Retriever $retriever;

    protected Ranker $ranker;

    public function __construct(protected int $tenantId)
    {
        $this->retriever = new Retriever($tenantId);
        $this->ranker = new Ranker();
    }

    public static function forTenant(int $tenantId): static
    {
        return new static($tenantId);
    }

    /**
     * Arama fallback'i: az/0 sonuçta BAĞLAM-DUYARLI kademeli öneri. Her sorgu global
     * "en çok satanlar"a düşmesin diye kademeler sorguya/sonuçlara demirlenir:
     *   A) Sorgu gerçek sonuç verdiyse + vektör varsa → SEMANTİK KUYRUK: alaka eşiğinin
     *      hemen altında kalan en yakın ürünler ("paten" → patene benzer ayakkabılar).
     *   B) Facet gevşetme: beden düşür — geriye anchor facet KALIYORSA ("kırmızı 44" →
     *      diğer beden kırmızılar; salt "46"da kalan facet yok, bu kademe atlanır).
     *   C) Yalnız kategori/marka.
     *   D) Gösterilen sonuçların KATEGORİLERİNDE popüler ("46" → o 1 ürünün kategorisi).
     *   E) Son çare: mağaza geneli popüler (yalnız saçma/anlamsız sorguda buraya düşülür).
     *
     * @param  array  $u  QueryUnderstanding çıktısı
     * @param  float[]|null  $vector
     * @param  array  $shown  Kullanıcıya gösterilen sonuçlar (dublike engeli + kategori anchor'ı)
     */
    public function forQuery(array $u, ?array $vector, int $limit = 12, array $shown = []): array
    {
        $facets = $u['facets'] ?? [];
        $terms = $u['terms'] ?? [];
        $exclude = array_map('intval', array_filter(array_column($shown, 'id')));

        // Segment koruması: yetişkin numara arayana ("43 kırmızı") beden gevşetme/popüler
        // kademelerinden ÇOCUK ürünü sızmasın — 43 arayan biri çocuk değildir.
        $beden = $facets['beden'] ?? null;
        if (is_array($beden)) {
            $beden = $beden[0] ?? null;
        }
        $adultMin = (int) config('search.recommendations.adult_min_size', 36);
        $noKids = is_numeric($beden) && (int) $beden >= $adultMin;
        $isKids = function (array $it): bool {
            $hay = (string) ($it['category'] ?? '') . ' ' . (string) ($it['name'] ?? '');
            foreach (['çocuk', 'cocuk', 'bebek', 'kids'] as $w) {
                if (mb_stripos($hay, $w, 0, 'UTF-8') !== false) {
                    return true;
                }
            }

            return false;
        };

        $picked = [];
        $add = function (array $items) use (&$picked, $limit, &$exclude, $noKids, $isKids) {
            foreach ($items as $it) {
                $id = (int) ($it['id'] ?? 0);
                if ($noKids && $isKids($it)) {
                    continue;
                }
                if ($id && ! isset($picked[$id]) && ! in_array($id, $exclude, true) && count($picked) < $limit) {
                    $picked[$id] = $it;
                }
            }
        };

        $relaxed = $facets;
        unset($relaxed['beden']);

        // A) Beden gevşet — kalan facet varsa ("kırmızı 44" → diğer beden kırmızılar);
        //    serbest terim + vektör varsa taşınır (niyet korunur)
        if (! empty($relaxed)) {
            $cands = $this->retriever->retrieve(['facets' => $relaxed, 'terms' => $terms], $vector, $limit * 4);
            $add($this->ranker->rank($cands, $u, $limit * 2));
        }

        // B) Yalnız kategori/marka
        if (count($picked) < $limit) {
            $cm = array_intersect_key($facets, array_flip(['category', 'brand']));
            if (! empty($cm)) {
                $cands = $this->retriever->retrieve(['facets' => $cm, 'terms' => []], null, $limit * 4);
                $add($this->ranker->rank($cands, [], $limit * 2));
            }
        }

        // C) Gösterilen sonuçların KATEGORİLERİNDE popüler — "paten" → çocuk spor/sneaker
        //    popülerleri, "46" → o ürünün kategorisi (insan mağazacının yapacağı şey)
        if (count($picked) < $limit && ! empty($shown)) {
            $cats = array_values(array_unique(array_filter(array_column($shown, 'category'))));
            if (! empty($cats)) {
                $add($this->popular(['category' => array_slice($cats, 0, 5)], $limit, array_merge($exclude, array_keys($picked))));
            }
        }

        // D) Semantik kuyruk (gate'siz) — alaka eşiğinin hemen altındaki en yakınlar (dolgu)
        if (count($picked) < $limit && ! empty($shown) && $vector !== null) {
            $cands = $this->retriever->retrieve(['facets' => [], 'terms' => $terms], $vector, $limit * 3);
            $add($this->ranker->rank($cands, $u, $limit * 2));
        }

        // E) Mağaza geneli popüler (saçma sorgu / hiç bağlam yok — son çare)
        if (count($picked) < $limit) {
            $add($this->popular(array_intersect_key($facets, array_flip(['category', 'brand'])), $limit, array_merge($exclude, array_keys($picked))));
        }

        return array_values($picked);
    }

    /** Verilen id'leri aday listesinden çıkar. */
    protected function exclude(array $candidates, array $excludeIds): array
    {
        if (empty($excludeIds)) {
            return $candidates;
        }
        $set = array_flip(array_map('intval', $excludeIds));

        return array_values(array_filter($candidates, fn ($c) => ! isset($set[(int) ($c['id'] ?? 0)])));
    }

    /** Embedding-benzeri ürünler (PDP "benzer ürünler", e-posta). pgvector cosine. */
    public function similarToProduct(int $productId, int $limit = 12): array
    {
        $stockCond = TenantSearchSettings::get($this->tenantId)['require_stock']
            ? ' and coalesce(p.stock_quantity, 0) > 0'
            : '';
        $rows = DB::select(
            'select p.id, p.name, p.brand, p.category, p.price, p.discounted_price, p.stock_quantity, p.images, p.url, p.metadata
             from products p
             where p.tenant_id = ? and p.id <> ? and p.is_active = true and p.embedding is not null
               and coalesce(p.url, \'\') <> \'\'
               and jsonb_typeof(p.images) = \'array\' and jsonb_array_length(p.images) > 0' . $stockCond . '
             order by p.embedding <=> (select embedding from products where id = ?) asc
             limit ?',
            [$this->tenantId, $productId, $productId, $limit]
        );

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /** Birlikte alınan ürünler (order_items co-purchase). Cross-sell. */
    public function boughtTogether(int $productId, int $limit = 12): array
    {
        $rows = DB::select(
            'select oi2.product_id as pid, count(distinct oi1.order_id) as freq
             from order_items oi1
             join order_items oi2 on oi2.order_id = oi1.order_id and oi2.product_id <> oi1.product_id
             where oi1.product_id = ? and oi1.tenant_id = ? and oi2.product_id is not null
             group by oi2.product_id
             order by freq desc
             limit ?',
            [$productId, $this->tenantId, $limit]
        );

        return $this->hydrate(array_map(fn ($r) => (int) $r->pid, $rows));
    }

    /** Kategori/marka içinde performansa göre popüler ürünler. */
    public function popular(array $facets = [], int $limit = 12, array $excludeIds = []): array
    {
        $q = DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true);

        $ts = TenantSearchSettings::get($this->tenantId);
        if ($ts['require_stock']) {
            $q->whereRaw('coalesce(stock_quantity, 0) > 0');
        }
        $q->whereRaw("coalesce(url, '') <> ''"); // sayfasız ürün önerilmez
        if ($ts['require_image']) {
            $q->whereRaw("jsonb_typeof(images) = 'array' and jsonb_array_length(images) > 0");
        }

        if (! empty($facets['category'])) {
            $q->whereIn('category', (array) $facets['category']);
        }
        if (! empty($facets['brand'])) {
            $q->whereIn('brand', (array) $facets['brand']);
        }
        if (! empty($excludeIds)) {
            $q->whereNotIn('id', array_map('intval', $excludeIds));
        }

        return $q->orderByRaw('performance_score desc nulls last')
            ->limit($limit)
            ->get(['id', 'name', 'brand', 'category', 'price', 'discounted_price', 'stock_quantity', 'images', 'url', 'metadata'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** id listesini, verilen sırayı koruyarak ürünlere çevir (aktif + stokta olanlar). */
    protected function hydrate(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->when(TenantSearchSettings::get($this->tenantId)['require_stock'], fn ($w) => $w->whereRaw('coalesce(stock_quantity, 0) > 0'))
            ->whereRaw("coalesce(url, '') <> ''")
            ->when(TenantSearchSettings::get($this->tenantId)['require_image'], fn ($w) => $w->whereRaw("jsonb_typeof(images) = 'array' and jsonb_array_length(images) > 0"))
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'brand', 'category', 'price', 'discounted_price', 'stock_quantity', 'images', 'url', 'metadata'])
            ->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $out[] = (array) $rows[$id];
            }
        }

        return $out;
    }
}
