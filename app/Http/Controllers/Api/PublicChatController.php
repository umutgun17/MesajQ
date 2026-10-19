<?php

namespace App\Http\Controllers\Api;

use App\Models\ChatSession;
use App\Services\Chat\ChatbotService;
use App\Services\Chat\ChatRollout;
use App\Services\Chat\TenantChatSettings;
use App\Services\Search\DiscoveryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HALKA AÇIK AI Chatbot (storefront ziyaretçisi). Auth YOK; slug ile tenant.
 * Bkz docs/ai-chatbot.md. CORS + enabled/domain kapısı ChatWidgetCors'ta.
 *
 * PublicSearchController'dan miras: resolveTenant, shopBase, present, imageList,
 * variantColors, logQuery (source='chat' ile), sessionId, isTestTraffic ve
 * event() (ürün kartı tıklaması → search_events; mevcut saatlik atıf cron'u
 * chatbot dönüşümlerini otomatik yakalar).
 */
class PublicChatController extends PublicSearchController
{
    /** GET w/{slug}/chat/config — widget açılış ayarları */
    public function chatConfig(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $s = TenantChatSettings::get($tenant->id);

        // KADEMELİ AÇILIM: kota dışındaki ziyaretçide widget hiç render edilmez.
        // Bkz ChatRollout — chatbot son tüketiciye görünen bir yüzey, herkese
        // birden açılmaz; panelden 10 → 100 → sınırsız diye büyütülür.
        $visitorId = $this->visitorId($request);
        if ($s['enabled'] && ! ChatRollout::canSee($tenant->id, $s, $visitorId)) {
            return response()->json(['enabled' => false, 'reason' => 'rollout_full']);
        }

        return response()->json([
            'enabled' => $s['enabled'],
            'bot_name' => $s['bot_name'],
            'greeting' => $s['greeting'],
            'quick_replies' => $s['quick_replies'],
            'handoff_enabled' => $s['handoff_enabled'],
            'theme_color' => $s['theme_color'],
            'template' => $s['template'],
            'greeting_style' => $s['greeting_style'],
            'teaser_text' => $s['teaser_text'],
            'launcher_style' => $s['launcher_style'],
        ]);
    }

    /** POST w/{slug}/chat/message — {session_key?, message, page_url?} */
    public function message(Request $request, string $slug, ChatbotService $bot): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $text = trim((string) $request->input('message', ''));
        if ($text === '' || mb_strlen($text) > (int) config('chat.max_message_length', 1000)) {
            return response()->json(['error' => 'invalid_message'], 422);
        }

        $settings = TenantChatSettings::get($tenant->id);
        if (! $settings['enabled']) {
            return response()->json(['error' => 'disabled'], 403);
        }

        // Slot ilk MESAJDA tahsis edilir: sayfayı açıp yazmayan ziyaretçi kotayı yakmaz.
        $visitorId = $this->visitorId($request);
        if (! ChatRollout::claim($tenant->id, $settings, $visitorId, $this->isTestTraffic($request))) {
            return response()->json(['error' => 'rollout_full'], 403);
        }

        $session = $this->resolveSession($tenant->id, $request, createIfMissing: true);
        if (! $session) {
            return response()->json(['error' => 'session_error'], 500);
        }

        if ($visitorId && ! $session->visitor_id) {
            $session->forceFill(['visitor_id' => $visitorId])->save();
        }

        // Ürün kartı sunucu closure'ı: DiscoveryEngine + present() + arama logu (atıf için)
        //
        // Kademeli eşleşme (4 Eyl): tam eşleşme yoksa ÖNCE bedeni, sonra rengi
        // düşürerek yeniden aranır; hiçbiri tutmazsa semantik öneriler gösterilir.
        // 'match' anahtarı ne olduğunu söyler — ChatbotService cümleyi ona göre
        // kurar ki "38 kırmızı" isteyene siyah ayakkabı "buldum" denmesin.
        $presentProducts = function (string $query, array $excludeIds = [], array $prefs = []) use ($tenant, $request): array {
            $engine = DiscoveryEngine::forTenant($tenant->id);
            $limit = (int) config('chat.products_limit', 4);

            // Sohbette biriken kısıtlar (bütçe + beden) motora YAPISAL filtre
            // olarak geçer. Sorgu METNİNE güvenmiyoruz: model ikinci turda
            // "44 numara"yı yazmayı unutunca müşteriye giymediği beden çıkıyordu.
            $priceMax = isset($prefs['price_max']) ? (float) $prefs['price_max'] : null;
            $bestSellers = ! empty($prefs['best_sellers']);
            $userFilters = [];
            if ($priceMax !== null) {
                $userFilters['price_max'] = $priceMax;
            }
            if (! empty($prefs['beden'])) {
                $userFilters['beden'] = [(string) $prefs['beden']];
            }
            $ctxFilters = $userFilters !== [] ? ['filters' => $userFilters] : [];

            // Aynı sohbette gösterilmiş ürünler elenir ("başka var mı" dendiğinde
            // aynı kartların dönmesi 4 Eyl konuşmalarındaki en görünür kusurdu).
            //
            // FOTOĞRAFSIZ ÜRÜN DE ELENİR (5 Eyl): katalogda 290 stoklu ürünün hiç
            // görseli yok ve sohbet kartı görselsiz boş bir kutu olarak çıkıyordu —
            // müşteriye "saçma sapan" bir öneri gibi görünüyor. Vitrin (best
            // sellers) sorgusu bunu zaten SQL'de şart koşuyordu; arama yolu
            // koşmuyordu. Kartı gösteremiyorsak ürünü hiç önermeyiz.
            $exclude = array_flip(array_map('intval', $excludeIds));
            $fresh = function (array $list) use ($exclude, $limit) {
                $out = [];
                foreach ($list as $p) {
                    if (isset($exclude[(int) ($p['id'] ?? 0)]) || ! $this->hasImage($p)) {
                        continue;
                    }
                    $out[] = $p;
                    if (count($out) >= $limit) {
                        break;
                    }
                }

                return $out;
            };

            // "En çok satan / ne önerirsin" → serbest arama YOK. Vitrin sırası
            // site listeleme sırasıyla aynı sinyalden gelir
            // (products.performance_score: satış+sepet+favori+görüntülenme+yorum).
            if ($bestSellers) {
                $top = $this->chatBestSellers($tenant->id, $userFilters, array_keys($exclude), $limit);
                if (! empty($top)) {
                    $base = $this->shopBase($tenant);
                    $colors = $this->variantColors($tenant->id, array_map(fn ($p) => (int) $p['id'], $top));

                    // Bu yol da loglanır: aksi halde vitrin kartlarına yapılan
                    // tıklamalar search_query_id'siz kalır ve ürün performansı
                    // raporunda sohbete bağlanamaz.
                    $searchId = $this->logQuery($tenant->id, $request, [
                        'query' => 'en çok satanlar',
                        'understanding' => ['normalized' => 'en çok satanlar', 'intent' => 'best_sellers', 'facets' => $userFilters],
                        'meta' => ['total' => count($top)],
                    ], 'chat');

                    return [
                        'products' => array_map(fn ($p) => $this->present($p, $base, true, $colors), $top),
                        'search_id' => $searchId,
                        'match' => 'best_sellers',
                        'dropped' => [],
                    ];
                }
                // Kısıtla hiç ürün yoksa (ör. 44 numara + 300 TL altı) normal
                // akışa düş; aşağıdaki kademeli gevşetme doğru cümleyi kurar.
                $query = $query !== '' ? $query : 'çok satan';
            }

            $result = $engine->search($query, $ctxFilters);
            $items = $fresh($result['results']);

            // İlk sayfa elendiyse havuzun devamına bak (24'erlik sayfalar)
            for ($page = 1; empty($items) && $page <= 3 && ($result['meta']['has_more'] ?? false); $page++) {
                $next = $engine->search($query, $ctxFilters + ['offset' => $page * 24]);
                $items = $fresh($next['results']);
                if (! empty($items)) {
                    $result = $next;
                }
            }

            $match = 'exact';
            $dropped = [];

            $wantedSize = $result['understanding']['facets']['beden'] ?? null;

            // Kademeli gevşetme, artık AÇIKÇA verilen filtreleri de düşürebilmeli:
            // beden/bütçe sohbet belleğinden geldiği için motorun drop_facets'i
            // tek başına yetmez (kullanıcının seçtiği filtreyi bilerek düşürmez).
            $searchWithout = function (array $dropKeys) use ($engine, $query, $userFilters) {
                $f = $userFilters;
                if (in_array('fiyat', $dropKeys, true)) {
                    unset($f['price_min'], $f['price_max']);
                }
                foreach (['beden', 'renk'] as $k) {
                    if (in_array($k, $dropKeys, true)) {
                        unset($f[$k]);
                    }
                }
                $facetDrops = array_values(array_intersect($dropKeys, ['beden', 'renk']));

                return $engine->search($query, ($f !== [] ? ['filters' => $f] : [])
                    + ($facetDrops !== [] ? ['drop_facets' => $facetDrops] : []));
            };

            // Fiyat tavanı İLK gevşetilen kısıttır: müşteri "600 TL altı" deyip
            // sonuç çıkmadıysa doğru cevap "o fiyatın altında yok, en yakınları
            // bunlar" — bedeni/rengi feda edip sessizce başka ürün göstermek değil.
            if (empty($items) && $priceMax !== null) {
                $noPrice = $searchWithout(['fiyat']);
                $items = $fresh($noPrice['results']);
                if (! empty($items)) {
                    $result = $noPrice;
                    $match = 'relaxed';
                    $dropped = ['fiyat'];
                }
            }

            // Sorgudan türeyen ya da sohbette söylenen fasetleri sırayla gevşet
            foreach ([['beden'], ['beden', 'renk']] as $drop) {
                if (! empty($items)) {
                    break;
                }
                $present = array_filter($drop, fn ($k) => ! empty($result['understanding']['facets'][$k] ?? null)
                    || ! empty($userFilters[$k]));
                if (count($present) !== count($drop)) {
                    continue; // o kısıt zaten yok — gevşetecek bir şey yok
                }
                $relaxed = $searchWithout(array_merge($drop, $dropped));
                // Beden düşünce "38 isteyene 30 numara çocuk ayakkabısı" çıkmasın:
                // o bedeni ÜRETEN modeller (stok şartı olmadan) öne alınır.
                $pool = $this->preferSize($tenant->id, $relaxed['results'], $wantedSize);
                $relaxedItems = $fresh($pool);
                if (! empty($relaxedItems)) {
                    $result = $relaxed;
                    $items = $relaxedItems;
                    $match = 'relaxed';
                    $dropped = $drop;
                }
            }

            if (empty($items)) {
                $items = $fresh($result['recommendations'] ?? []);
                $match = empty($items) ? 'none' : 'similar';
            }

            $searchId = $this->logQuery($tenant->id, $request, $result, 'chat');
            $base = $this->shopBase($tenant);
            $colors = $this->variantColors($tenant->id, array_map(fn ($p) => (int) ($p['id'] ?? 0), $items));

            return [
                'products' => array_map(fn ($p) => $this->present($p, $base, true, $colors), $items),
                'search_id' => $searchId,
                'match' => $match,
                'dropped' => $dropped,
            ];
        };

        $out = $bot->handle($tenant, $session, $text, $presentProducts);

        return response()->json([
            'session_key' => $session->session_key,
            'status' => $out['status'],
            'messages' => $out['messages'],
        ]);
    }

    /**
     * GET w/{slug}/chat/messages?session_key=&after_id= — polling.
     * Handoff sonrası personel cevapları (role=agent) buradan widget'a ulaşır;
     * ayrıca sayfa yenilenince geçmişi geri yükler.
     */
    public function messages(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($slug);
        if (! $tenant) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $session = $this->resolveSession($tenant->id, $request, createIfMissing: false);
        if (! $session) {
            return response()->json(['messages' => [], 'status' => 'none']);
        }

        // Ziyaretçi penceresi açık: yoklama her 5 sn'de geliyor, yazmayı 30 sn'ye
        // seyrelt. Personel cevabının SMS'e mi düşeceği buna bakıyor
        // (bkz MessageController::webchatVisitorPresent).
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subSeconds(30))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        $afterId = (int) $request->query('after_id', 0);

        $messages = $session->messages()
            ->where('id', '>', $afterId)
            ->whereIn('role', ['user', 'assistant', 'agent'])
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'products' => $m->payload['products'] ?? [],
                'quick_replies' => $m->payload['quick_replies'] ?? [],
                'search_id' => $m->payload['search_id'] ?? null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'messages' => $messages,
            'status' => $session->status,
        ]);
    }

    protected function resolveSession(int $tenantId, Request $request, bool $createIfMissing): ?ChatSession
    {
        $key = trim((string) $request->input('session_key', $request->query('session_key', '')));

        if ($key !== '' && strlen($key) <= 64) {
            $session = ChatSession::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('session_key', $key)
                ->first();
            if ($session) {
                return $session;
            }
        }

        if (! $createIfMissing) {
            return null;
        }

        return ChatSession::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'session_key' => bin2hex(random_bytes(24)),
            'status' => 'bot',
            'visitor_id' => $this->visitorId($request),
            'page_url' => mb_substr((string) $request->input('page_url', ''), 0, 500) ?: null,
            'last_activity_at' => now(),
            'is_test' => $this->isTestTraffic($request),
        ]);
    }

    /**
     * Tarayıcıda üretilen kalıcı ziyaretçi kimliği (localStorage). Oturumdan
     * (session_key) ayrıdır: aynı kişi yeni sohbet başlatsa da kotada tek sayılır.
     */
    protected function visitorId(Request $request): ?string
    {
        $v = trim((string) $request->input('visitor_id', $request->query('visitor_id', '')));

        return preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $v) ? $v : null;
    }

    /**
     * "En çok satan / ne önerirsin" cevabı: mağazanın vitrini.
     *
     * Neden ayrı yol: bunu serbest aramaya çevirmek yanlıştı — motor "en çok
     * satan" ifadesini ürün adı sanıp eşleşme bulamıyor, bot da "aradığınız
     * özellikte ürün bulamadım" diyordu (5 Eyl testi). Sıralama SİTE LİSTELEME
     * SIRASIYLA aynı sinyalden gelir: products.performance_score
     * (satış + sepete ekleme + favori + görüntülenme + yorum, FeedPerformanceLabelService).
     *
     * @param  array<string, mixed>  $filters  beden / price_max (sohbette söylenmiş kısıtlar)
     * @param  array<int, int>  $excludeIds  bu sohbette zaten gösterilenler
     * @return array<int, array<string, mixed>>
     */
    /**
     * Ürünün gösterilebilir en az bir görseli var mı? (jsonb dizi ya da JSON
     * string olarak gelebilir — sync formatı ikisini de üretebiliyor.)
     */
    protected function hasImage(array $p): bool
    {
        $img = $p['images'] ?? null;
        if (is_string($img)) {
            $img = json_decode($img, true);
        }
        if (is_array($img)) {
            foreach ($img as $one) {
                $url = is_array($one) ? ($one['url'] ?? $one['src'] ?? '') : $one;
                if (trim((string) $url) !== '') {
                    return true;
                }
            }
        }

        return trim((string) ($p['image'] ?? '')) !== '';
    }

    protected function chatBestSellers(int $tenantId, array $filters, array $excludeIds, int $limit): array
    {
        $q = \Illuminate\Support\Facades\DB::table('products as p')
            ->where('p.tenant_id', $tenantId)
            ->where('p.is_active', true)
            ->where('p.stock_quantity', '>', 0)
            ->whereRaw("jsonb_typeof(p.images) = 'array' and jsonb_array_length(p.images) > 0")
            ->whereNotNull('p.performance_score');

        if ($excludeIds !== []) {
            $q->whereNotIn('p.id', array_slice(array_map('intval', $excludeIds), -200));
        }

        if (isset($filters['price_max'])) {
            $q->whereRaw('case when coalesce(p.discounted_price,0) > 0 and p.discounted_price < p.price'
                . ' then p.discounted_price else p.price end <= ?', [(float) $filters['price_max']]);
        }

        // Beden kısıtı VARYANT düzeyinde ve STOKTA olmalı — "44 numara" diyene
        // 44'ü tükenmiş bir çok-satanı önermek en görünür hatadır.
        $beden = array_values(array_filter((array) ($filters['beden'] ?? [])));
        if ($beden !== []) {
            $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('product_variants as v')
                ->whereColumn('v.product_id', 'p.id')
                ->whereRaw('coalesce(v.stock_quantity, 0) > 0')
                ->whereIn(\Illuminate\Support\Facades\DB::raw("v.attributes->>'Beden'"), $beden));
            $q->selectRaw("exists(select 1 from product_variants v2 where v2.product_id = p.id"
                . " and coalesce(v2.stock_quantity,0) > 0 and v2.attributes->>'Beden' in ("
                . implode(',', array_fill(0, count($beden), '?')) . ')) as facet_in_stock', $beden);
        } else {
            $q->selectRaw('NULL::boolean as facet_in_stock');
        }

        $rows = $q->addSelect(['p.id', 'p.name', 'p.brand', 'p.category', 'p.price', 'p.discounted_price',
            'p.stock_quantity', 'p.images', 'p.url', 'p.performance_score', 'p.metadata'])
            ->orderByDesc('p.performance_score')
            ->orderBy('p.id')
            ->limit($limit)
            ->get();

        return array_map(fn ($r) => (array) $r, $rows->all());
    }

    /**
     * Beden faseti gevşetilince sonuçları yeniden sıralar: istenen bedeni ÜRETEN
     * modeller (o bedende varyantı olan — stokta olması şart değil) başa gelir.
     * Amaç, "38 kırmızı" arayana 30 numara çocuk ayakkabısı göstermemek; kartlar
     * yine stoktaki bedenlerden gelir (arama motoru stok şartını uygular).
     *
     * @param  array<int, array>  $items
     * @return array<int, array>
     */
    protected function preferSize(int $tenantId, array $items, mixed $size): array
    {
        $size = is_array($size) ? ($size[0] ?? null) : $size;
        $size = $size === null ? '' : trim((string) $size);
        if ($size === '' || count($items) < 2) {
            return $items;
        }

        $ids = array_values(array_filter(array_map(fn ($p) => (int) ($p['id'] ?? 0), $items)));
        if (empty($ids)) {
            return $items;
        }

        $hits = DB::table('product_variants')
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $ids)
            ->whereRaw("attributes->>'Beden' = ?", [$size])
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (empty($hits) || count($hits) === count($ids)) {
            return $items; // ayrım yok — sırayı bozma
        }

        $hits = array_flip($hits);
        $first = [];
        $rest = [];
        foreach ($items as $p) {
            isset($hits[(int) ($p['id'] ?? 0)]) ? $first[] = $p : $rest[] = $p;
        }

        return array_merge($first, $rest);
    }
}
