<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchEvent;
use App\Models\SearchQuery;
use App\Models\Tenant;
use App\Services\Search\DiscoveryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HALKA AÇIK akıllı arama (storefront ziyaretçisi). Auth YOK; slug ile tenant.
 *
 * Public bağlamda Eloquent tenant global-scope pasiftir → her sorgu/yazma açıkça tenant_id ile
 * sınırlanır (DiscoveryEngine zaten tenant'a bağlı; log kayıtlarında tenant_id elle set edilir).
 */
class PublicSearchController extends Controller
{
    /** GET w/{slug}/search?q=... */
    public function search(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < (int) config('search.retrieval.min_query_length', 2)) {
            return response()->json([
                'query' => $q, 'understanding' => null,
                'results' => [], 'recommendations' => [], 'meta' => ['total' => 0],
            ]);
        }

        // offset: sonsuz kaydırma sayfalaması (havuz sınırıyla clamp'li)
        $offset = min(
            max(0, (int) $request->query('offset', 0)),
            (int) config('search.retrieval.candidate_limit', 200)
        );

        $result = DiscoveryEngine::forTenant($tenant->id)->search($q, [
            'offset' => $offset,
            'filters' => $this->parseFilters($request),
        ]);

        // log=0 → canlı önizleme (yazarken), loglanmaz. Varsayılan/commit → loglanır.
        // Sayfalama istekleri (offset>0) aynı aramanın devamı — asla ikinci kez loglanmaz.
        $searchId = ($offset > 0 || $request->query('log', '1') === '0')
            ? null
            : $this->logQuery($tenant->id, $request, $result, 'search');

        $base = $this->shopBase($tenant);
        $badgeOn = \App\Services\Search\TenantSearchSettings::get($tenant->id)['badge_enabled'];

        // Kart zenginleştirme: gösterilen ürünlerin varyant renklerini TEK sorguda topla
        // (swatch için). N+1 yerine toplu; renk aramasında ARANAN renk önce gelsin diye
        // present() sırasını bozmadan map olarak geçiyoruz.
        $shown = array_merge($result['results'], $result['recommendations']);
        $colorsByProduct = $this->variantColors($tenant->id, array_map(fn ($p) => (int) ($p['id'] ?? 0), $shown));

        return response()->json([
            'query' => $result['query'],
            'understanding' => $result['understanding'],
            'results' => array_map(fn ($p) => $this->present($p, $base, $badgeOn, $colorsByProduct), $result['results']),
            'recommendations' => array_map(fn ($p) => $this->present($p, $base, $badgeOn, $colorsByProduct), $result['recommendations']),
            'facets' => $result['facets'] ?? null,
            'applied_filters' => $result['applied_filters'] ?? [],
            'meta' => $result['meta'],
            'search_id' => $searchId,
        ]);
    }

    /** GET w/{slug}/search/autocomplete?q=... */
    public function autocomplete(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < (int) config('search.retrieval.min_query_length', 2)) {
            return response()->json(['suggestions' => []]);
        }

        $items = DiscoveryEngine::forTenant($tenant->id)->autocomplete($q);
        $base = $this->shopBase($tenant);

        return response()->json(['suggestions' => array_map(fn ($p) => $this->present($p, $base), $items)]);
    }

    /** POST w/{slug}/search/event  (tıklama/sepete ekleme/dönüşüm) */
    public function event(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['ok' => false], 404);
        }
        if (! config('search.events.log_clicks', true)) {
            return response()->json(['ok' => false]);
        }

        // sendBeacon preflight'a takilmasin diye widget text/plain gonderir; body'yi elle coz
        if (! $request->isJson() && $request->getContent() !== '') {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $request->merge($decoded);
            }
        }

        $type = (string) $request->input('type', 'click');
        if (! in_array($type, ['impression', 'click', 'add_to_cart', 'conversion'], true)) {
            return response()->json(['ok' => false], 422);
        }

        SearchEvent::create([
            'tenant_id' => $tenant->id,
            'search_query_id' => $request->filled('search_id') ? (int) $request->input('search_id') : null,
            'product_id' => $request->filled('product_id') ? (int) $request->input('product_id') : null,
            'platform_product_id' => $request->input('platform_product_id'),
            'type' => $type,
            'position' => $request->filled('position') ? (int) $request->input('position') : null,
            // conversion: aramadan ciro (sipariş tutarı + no)
            'value' => ($type === 'conversion' && $request->filled('value')) ? (float) $request->input('value') : null,
            'platform_order_id' => $type === 'conversion' ? $request->input('order_id') : null,
            'session_id' => $this->sessionId($request),
            // mağaza sepet kimliği (ikas: sipariş id ile aynı) → IP'siz platformlarda satış atfı
            'cart_id' => mb_substr(preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $request->input('cart_id', '')), 0, 64) ?: null,
            'is_test' => $this->isTestTraffic($request),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET w/{slug}/search/config — widget davranış ayarları (panelden yönetilir).
     * Tetikleyici/input seçicileri script attribute'u yerine buradan gelir:
     * merchant paneli değiştirince temaya dokunmadan anında etki eder.
     */
    public function config(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $ts = \App\Services\Search\TenantSearchSettings::get($tenant->id);

        return response()->json([
            'trigger' => $ts['trigger'],
            'input' => $ts['input'],
            'template' => $ts['template'],
            'theme' => $ts['theme'] ?? \App\Services\Search\TenantSearchSettings::theme(null),
            'placeholders' => ($ts['placeholders'] ?? []) ?: $this->autoPlaceholders($tenant->id),
        ]);
    }

    /** GET w/{slug}/search/popular  — en çok aranan terimler (boşken boş döner, veri arttıkça dolar). */
    public function popular(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['terms' => []]);
        }

        $terms = Cache::remember("search:popular:{$tenant->id}", 1800, function () use ($tenant) {
            // Gerçek trafik yoksa (yeni tenant / go-live öncesi) test/demo aramalarına düş —
            // boş "Popüler aramalar" bölümünden iyidir; gerçek veri dolunca ona döner.
            $terms = $this->popularTerms($tenant->id, false);

            return ! empty($terms) ? $terms : $this->popularTerms($tenant->id, true);
        });

        // Görselli popüler kategoriler (boş durumda keşif) — kataloğun en dolu kategorileri
        $categories = Cache::remember("search:popcat:{$tenant->id}", 3600, function () use ($tenant) {
            return $this->popularCategories($tenant->id);
        });

        $base = $this->shopBase($tenant);
        $categories = array_map(function ($c) use ($base) {
            $img = $this->imageList($c['image'] ?? null, 1)[0] ?? null;

            return ['name' => $c['name'], 'count' => (int) $c['count'], 'image' => $img];
        }, $categories);

        return response()->json(['terms' => $terms, 'categories' => $categories]);
    }

    /**
     * Widget filtre paramlarını normalize et. Çoklu değerler virgülle:
     *   f_category, f_brand, f_renk, f_beden (CSV) + price_min, price_max (sayı).
     */
    protected function parseFilters(Request $request): array
    {
        $csv = function ($v) {
            if (! is_string($v) || $v === '') {
                return [];
            }

            return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
        };

        $filters = [];
        foreach (['category' => 'f_category', 'brand' => 'f_brand', 'renk' => 'f_renk', 'beden' => 'f_beden'] as $key => $param) {
            $vals = $csv($request->query($param));
            if (! empty($vals)) {
                $filters[$key] = array_slice($vals, 0, 20); // güvenlik: aşırı çoklu değeri kırp
            }
        }
        foreach (['price_min', 'price_max'] as $param) {
            $v = $request->query($param);
            if (is_numeric($v)) {
                $filters[$param] = (float) $v;
            }
        }

        return $filters;
    }

    // ── yardımcılar ──

    /** Örnek sorgular (ayar boşsa): en dolu kategorilerin adları küçük harfle, 6 adet — mağazaya göre değişir. 1 saat önbellek. */
    protected function autoPlaceholders(int $tenantId): array
    {
        return Cache::remember("search:ph:{$tenantId}", 3600, function () use ($tenantId) {
            $out = [];
            foreach ($this->popularCategories($tenantId, 8) as $c) {
                $name = mb_strtolower(trim((string) $c['name']), 'UTF-8');
                if ($name !== '' && mb_strlen($name) <= 30 && ! in_array($name, $out, true)) {
                    $out[] = $name;
                }
                if (count($out) >= 6) {
                    break;
                }
            }

            return $out;
        });
    }

    /**
     * En çok aranan terimler. Salt sayı ("47") ve 3 harften kısa kayıtlar elenir;
     * yazarken loglanan fragment'lar ("kır" ← "kırmızı 44") daha uzun terime yutulur.
     */
    protected function popularTerms(int $tenantId, bool $includeTest): array
    {
        $q = DB::table('search_queries')
            ->where('tenant_id', $tenantId)
            ->where('has_results', true)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('normalized_query')
            ->whereRaw("normalized_query ~ '[[:alpha:]]'")
            ->whereRaw('char_length(normalized_query) >= 3');

        if (! $includeTest) {
            $q->where('is_test', false);
        }

        $rows = $q->selectRaw('normalized_query as q, count(*) as c')
            ->groupBy('normalized_query')
            ->orderByRaw('c desc')
            ->limit(24)
            ->get();

        $terms = [];
        foreach ($rows as $row) {
            $isFragment = false;
            foreach ($rows as $other) {
                if ($other->q !== $row->q && str_starts_with($other->q, $row->q)) {
                    $isFragment = true;
                    break;
                }
            }
            if (! $isFragment) {
                $terms[] = $row->q;
            }
            if (count($terms) >= 8) {
                break;
            }
        }

        return $terms;
    }

    /**
     * En dolu kategoriler + her birine temsili görsel (boş durumda "Popüler Kategoriler").
     * Yalnız aktif/stokta/görselli/sayfası olan ürünler sayılır; görsel en iyi performanslı
     * üründen. Kategorisiz/rozet-benzeri ("TEK FİYAT" gibi tek-kelime price-type) elenir.
     *
     * @return array<int, array{name:string, count:int, image:mixed}>
     */
    protected function popularCategories(int $tenantId, int $limit = 8): array
    {
        $rows = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereRaw("coalesce(category, '') <> ''")
            ->whereRaw('coalesce(stock_quantity, 0) > 0')
            ->whereRaw("coalesce(url, '') <> ''")
            ->whereRaw("jsonb_typeof(images) = 'array' and jsonb_array_length(images) > 0")
            ->selectRaw("category as name, count(*) as count,
                (array_agg(images ORDER BY performance_score desc nulls last))[1] as image")
            ->groupBy('category')
            ->orderByRaw('count desc')
            ->limit($limit * 2) // filtreleme sonrası yeterli kalsın
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) $r->name);
            // "TEK FİYAT" gibi kampanya/price-type değerleri kategori değildir — ele
            if ($name === '' || mb_strtoupper($name, 'UTF-8') === $name) {
                continue;
            }
            $out[] = ['name' => $name, 'count' => (int) $r->count, 'image' => $r->image];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    protected function resolveTenant(string $slug): ?Tenant
    {
        return Tenant::where('slug', $slug)->where('is_active', true)->first();
    }

    protected function logQuery(int $tenantId, Request $request, array $result, string $source): ?int
    {
        if (! config('search.events.log_queries', true)) {
            return null;
        }

        $u = $result['understanding'] ?? [];
        $total = (int) ($result['meta']['total'] ?? 0);

        $row = SearchQuery::create([
            'tenant_id' => $tenantId,
            'session_id' => $this->sessionId($request),
            'raw_query' => mb_substr($result['query'] ?? '', 0, 160),
            'normalized_query' => isset($u['normalized']) ? mb_substr($u['normalized'], 0, 160) : null,
            'parsed_facets' => $u['facets'] ?? null,
            'intent' => $u['intent'] ?? null,
            'results_count' => $total,
            'has_results' => $total > 0,
            'source' => $source,
            'device' => $request->header('Sec-CH-UA-Mobile') === '?1' ? 'mobile' : null,
            'ip_address' => $request->ip(),
            'is_test' => $this->isTestTraffic($request),
        ]);

        return $row->id;
    }

    protected function sessionId(Request $request): ?string
    {
        $sid = trim((string) $request->input('sid', ''));

        return $sid !== '' ? mb_substr($sid, 0, 64) : null;
    }

    /**
     * Hub'ın kendi domain'inden (demo/test sayfaları) gelen trafik = test.
     * Storefront origin'leri (tonnyblack.com.tr vb.) gerçek sayılır — analitik/popüler
     * aramalar demo oyunlarıyla kirlenmez.
     */
    protected function isTestTraffic(Request $request): bool
    {
        $src = $request->header('Origin') ?: $request->header('Referer');
        if (! $src) {
            return false;
        }
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        $hubHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host !== '' && $host === $hubHost;
    }

    /** Tenant'ın mağaza kök adresi (relative ürün URL'lerini mutlaklaştırmak için). */
    protected function shopBase(Tenant $tenant): string
    {
        return rtrim((string) (($tenant->settings ?? [])['shop_url'] ?? ''), '/');
    }

    /**
     * Gösterilen ürünlerin STOKTA olan varyant renklerini tek sorguda getir (kart swatch'ı).
     * ['product_id' => ['Kırmızı','Siyah',...]] — ürün başına en çok 8 renk.
     *
     * @param  int[]  $ids
     * @return array<int, string[]>
     */
    protected function variantColors(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        $rows = DB::table('product_variants')
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $ids)
            ->whereRaw('coalesce(stock_quantity, 0) > 0')
            ->whereNotNull(DB::raw("attributes->>'Renk'"))
            ->selectRaw("product_id, attributes->>'Renk' as renk")
            ->distinct()
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $renk = trim((string) $r->renk);
            if ($renk === '') {
                continue;
            }
            $pid = (int) $r->product_id;
            $map[$pid] ??= [];
            if (count($map[$pid]) < 8 && ! in_array($renk, $map[$pid], true)) {
                $map[$pid][] = $renk;
            }
        }

        return $map;
    }

    /** Ürün adayını storefront'a dönecek güvenli/temiz biçime çevir (iç skor/sinyal sızdırma). */
    protected function present(array $p, string $base = '', bool $badgeEnabled = true, array $colorsByProduct = []): array
    {
        // Renk/beden aramasında rozet ARANAN varyantın stoğunu yansıtır (facet_in_stock);
        // ürünün başka bedeni stokta diye "stokta" deme.
        $facetStock = $p['facet_in_stock'] ?? null;
        $inStock = $facetStock !== null
            ? ($facetStock === true || $facetStock === 't' || $facetStock === 1 || $facetStock === '1' || $facetStock === 'true')
            : ((float) ($p['stock_quantity'] ?? 0)) > 0;

        // Ticimax ürün URL'leri relative path ("/rahat-kalip-...") — mağaza domain'ine çevir,
        // yoksa tıklama widget'ın gömülü olduğu sayfada (demo'da hub) çözümlenir.
        $url = $p['url'] ?? null;
        if (is_string($url) && $url !== '' && $base !== '' && ! preg_match('~^https?://~i', $url)) {
            $url = $base . '/' . ltrim($url, '/');
        }

        // Kampanya rozeti (Ticimax özel alanı, sync metadata'ya yazar — ör. "1 ALANA 1 BEDAVA");
        // panelden tenant bazında kapatılabilir
        $badge = null;
        $badgeKey = $badgeEnabled ? (string) config('search.presentation.badge_metadata_key', 'ozel_alan1') : '';
        if ($badgeKey !== '') {
            $meta = $p['metadata'] ?? null;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (is_array($meta)) {
                $b = trim((string) ($meta[$badgeKey] ?? ''));
                $badge = $b !== '' ? mb_substr($b, 0, 60) : null;
            }
        }

        // Kart görsel karuseli: ilk birkaç görseli döndür (widget hover/kaydırmayla değiştirir).
        // 'image' geriye uyumluluk için ilk görsel olarak kalır.
        $images = $this->imageList($p['images'] ?? null, 4);

        return [
            'id' => $p['id'] ?? null,
            'name' => $p['name'] ?? null,
            'brand' => $p['brand'] ?? null,
            'category' => $p['category'] ?? null,
            'price' => $p['price'] ?? null,
            'discounted_price' => $p['discounted_price'] ?? null,
            'url' => $url,
            'image' => $images[0] ?? null,
            'images' => $images,
            // Varyant renk swatch'ları (stokta olanlar); yoksa boş dizi
            'variant_colors' => $colorsByProduct[(int) ($p['id'] ?? 0)] ?? [],
            'in_stock' => $inStock,
            'badge' => $badge,
        ];
    }

    /**
     * Görsel dizisini normalize et: jsonb string/dizi → en fazla $max mutlak URL.
     * Ticimax formatı düz string dizisi; obje formatı ({url}/{src}) da desteklenir.
     *
     * @return string[]
     */
    protected function imageList($images, int $max = 4): array
    {
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($images) || empty($images)) {
            return [];
        }

        $out = [];
        foreach ($images as $img) {
            $u = is_array($img) ? ($img['url'] ?? $img['src'] ?? ($img[0] ?? null)) : $img;
            if (is_string($u) && $u !== '') {
                $out[] = $u;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    protected function firstImage($images): ?string
    {
        return $this->imageList($images, 1)[0] ?? null;
    }
}
