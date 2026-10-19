<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AI Chatbot PANELİ (auth + tenant) — analitik + konuşma transkripti.
 * Bkz docs/ai-chatbot.md. Kim yazdı / ne yazdı / konuşma ağırlığı / sonucu.
 *
 * "Sohbetten ciro": bot ürün kartı gösterince search_queries'e source='chat' yazılır,
 * kart tıklamaları search_events'e düşer ve mevcut saatlik atıf cron'u
 * (search:attribute-conversions) dönüşümleri bağlar — burada sadece okunur.
 */
class ChatbotAdminController extends Controller
{
    protected function tenantId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->tenant_id) {
            return (int) $user->tenant_id;
        }

        return request()->header('X-Tenant-Id') ? (int) request()->header('X-Tenant-Id') : null;
    }

    /** GET /chat/analytics?range=7|30|90 */
    public function analytics(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        if (! $tenantId) {
            return response()->json(['error' => 'tenant_required'], 422);
        }

        $range = (int) $request->query('range', 30);
        if (! in_array($range, [7, 30, 90], true)) {
            $range = 30;
        }
        $since = now()->subDays($range);

        $base = DB::table('chat_sessions')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since);

        // ── Özet kartlar ──
        $totals = (clone $base)->selectRaw("
            count(*) AS sessions,
            count(*) FILTER (WHERE user_message_count > 0) AS engaged,
            coalesce(sum(user_message_count), 0) AS user_messages,
            count(*) FILTER (WHERE status = 'handoff') AS handoffs,
            count(*) FILTER (WHERE contact IS NOT NULL) AS with_contact,
            count(*) FILTER (WHERE is_test) AS test_sessions
        ")->first();

        $msgStats = DB::table('chat_messages')
            ->join('chat_sessions', 'chat_sessions.id', '=', 'chat_messages.chat_session_id')
            ->where('chat_sessions.tenant_id', $tenantId)
            ->where('chat_messages.created_at', '>=', $since)
            ->selectRaw("
                count(*) FILTER (WHERE chat_messages.role = 'assistant') AS bot_messages,
                count(*) FILTER (WHERE chat_messages.role = 'agent') AS agent_messages,
                count(*) FILTER (WHERE chat_messages.role = 'assistant'
                    AND chat_messages.payload->'products' IS NOT NULL
                    AND jsonb_array_length(chat_messages.payload->'products') > 0) AS product_messages
            ")->first();

        // Ürün kartı tıklamaları (widget sid = chat session_key)
        $clicks = (int) DB::table('search_events')
            ->join('chat_sessions', 'chat_sessions.session_key', '=', 'search_events.session_id')
            ->where('chat_sessions.tenant_id', $tenantId)
            ->where('search_events.tenant_id', $tenantId)
            ->where('search_events.type', 'click')
            ->where('search_events.created_at', '>=', $since)
            ->count();

        // Sohbetten atıflı ciro (source='chat' sorguları → conversion event'leri)
        $rev = DB::table('search_events')
            ->join('search_queries', 'search_queries.id', '=', 'search_events.search_query_id')
            ->where('search_queries.tenant_id', $tenantId)
            ->where('search_queries.source', 'chat')
            ->where('search_events.type', 'conversion')
            ->where('search_events.created_at', '>=', $since)
            ->selectRaw('count(DISTINCT search_events.platform_order_id) AS orders, coalesce(sum(search_events.value), 0) AS revenue')
            ->first();

        // ── Konuşma listesi (son 100) ──
        $sessions = (clone $base)
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get(['id', 'session_key', 'status', 'contact', 'page_url', 'user_message_count', 'last_activity_at', 'created_at', 'is_test', 'conversation_id']);

        $ids = $sessions->pluck('id')->all();
        $agg = empty($ids) ? collect() : DB::table('chat_messages')
            ->whereIn('chat_session_id', $ids)
            ->selectRaw("
                chat_session_id,
                count(*) AS total,
                count(*) FILTER (WHERE role = 'agent') AS agent_msgs,
                bool_or(payload->'products' IS NOT NULL AND jsonb_array_length(payload->'products') > 0) AS has_products
            ")
            ->groupBy('chat_session_id')
            ->get()
            ->keyBy('chat_session_id');

        // İlk kullanıcı mesajı = konuşma başlığı
        $firsts = empty($ids) ? collect() : DB::table('chat_messages')
            ->whereIn('chat_session_id', $ids)
            ->where('role', 'user')
            ->selectRaw('DISTINCT ON (chat_session_id) chat_session_id, content')
            ->orderByRaw('chat_session_id, id')
            ->get()
            ->keyBy('chat_session_id');

        $list = $sessions->map(function ($s) use ($agg, $firsts) {
            $a = $agg[$s->id] ?? null;
            $contact = json_decode((string) $s->contact, true) ?: [];

            $outcome = 'bos';
            if ((int) $s->user_message_count > 0) {
                $outcome = 'cevaplandi';
            }
            if ($a && $a->has_products) {
                $outcome = 'urun_onerildi';
            }
            if ($s->status === 'handoff') {
                $outcome = 'handoff';
            }

            return [
                'id' => $s->id,
                'visitor' => $contact['name'] ?? ($contact['email'] ?? ($contact['phone'] ?? null)),
                'contact' => $contact,
                'first_message' => mb_substr((string) ($firsts[$s->id]->content ?? ''), 0, 120),
                'user_messages' => (int) $s->user_message_count,
                'total_messages' => (int) ($a->total ?? 0),
                'agent_messages' => (int) ($a->agent_msgs ?? 0),
                'status' => $s->status,
                'outcome' => $outcome,
                'page_url' => $s->page_url,
                'is_test' => (bool) $s->is_test,
                'started_at' => $s->created_at,
                'last_activity_at' => $s->last_activity_at,
            ];
        })->values();

        return response()->json([
            'range' => $range,
            'summary' => [
                'sessions' => (int) $totals->sessions,
                'engaged_sessions' => (int) $totals->engaged,
                'test_sessions' => (int) $totals->test_sessions,
                'user_messages' => (int) $totals->user_messages,
                'bot_messages' => (int) ($msgStats->bot_messages ?? 0),
                'agent_messages' => (int) ($msgStats->agent_messages ?? 0),
                'handoffs' => (int) $totals->handoffs,
                'handoff_rate' => $totals->engaged > 0 ? round($totals->handoffs / $totals->engaged * 100, 1) : 0,
                'with_contact' => (int) $totals->with_contact,
                'product_messages' => (int) ($msgStats->product_messages ?? 0),
                'product_clicks' => $clicks,
                'attributed_orders' => (int) ($rev->orders ?? 0),
                'attributed_revenue' => (float) ($rev->revenue ?? 0),
                'avg_user_messages' => $totals->engaged > 0 ? round($totals->user_messages / $totals->engaged, 1) : 0,
            ],
            'conversations' => $list,
        ]);
    }

    /** GET /chat/sessions/{id}/messages — konuşma transkripti (panel drill-down) */
    public function sessionMessages(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        if (! $tenantId) {
            return response()->json(['error' => 'tenant_required'], 422);
        }

        $session = ChatSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $session) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $messages = $session->messages()
            ->orderBy('id')
            ->limit(300)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                // Personel, müşteriye ne gösterildiğini birebir görsün: görselli kart
                // (widget'takiyle aynı alanlar). Bkz ChatbotService::chatProductCards().
                'products' => \App\Services\Chat\ChatbotService::chatProductCards($m->payload['products'] ?? []),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values();

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'contact' => $session->contact,
                'page_url' => $session->page_url,
                'is_test' => $session->is_test,
                'started_at' => $session->created_at?->toIso8601String(),
                'conversation_id' => $session->conversation_id,
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * GET /chat/conversations — AYRI "Chatbot Konuşmaları" sayfası.
     * Analiz sekmesindeki son-100 listesinden farkı: sayfalama, arama ve
     * sonuç filtresi var; amaç pilotta her konuşmayı tek tek okumak.
     *
     * Filtreler: outcome=otomatik|devir|urun|bos, q=metin, days, include_test
     */
    public function conversations(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        if (! $tenantId) {
            return response()->json(['error' => 'tenant_required'], 422);
        }

        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $outcome = (string) $request->query('outcome', '');
        $q = trim((string) $request->query('q', ''));
        $includeTest = filter_var($request->query('include_test', false), FILTER_VALIDATE_BOOLEAN);

        $base = DB::table('chat_sessions as cs')
            ->where('cs.tenant_id', $tenantId)
            ->where('cs.created_at', '>=', now()->subDays($days))
            ->where('cs.user_message_count', '>', 0);

        if (! $includeTest) {
            $base->where('cs.is_test', false);
        }

        if ($outcome === 'devir') {
            $base->where('cs.status', 'handoff');
        } elseif ($outcome === 'otomatik') {
            $base->where('cs.status', '!=', 'handoff');
        }

        if ($q !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $base->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(cs.contact::text) LIKE ?', [$like])
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->selectRaw('1')
                            ->from('chat_messages as cm')
                            ->whereColumn('cm.chat_session_id', 'cs.id')
                            ->whereRaw('LOWER(cm.content) LIKE ?', [$like]);
                    });
            });
        }

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->orderByDesc('cs.last_activity_at')
            ->forPage($page, $perPage)
            ->get(['cs.id', 'cs.session_key', 'cs.visitor_id', 'cs.status', 'cs.contact', 'cs.context',
                'cs.page_url', 'cs.user_message_count', 'cs.last_activity_at', 'cs.created_at',
                'cs.is_test', 'cs.conversation_id']);

        $ids = $rows->pluck('id')->all();

        $agg = empty($ids) ? collect() : DB::table('chat_messages')
            ->whereIn('chat_session_id', $ids)
            ->selectRaw("
                chat_session_id,
                count(*) AS total,
                count(*) FILTER (WHERE role = 'assistant') AS bot_msgs,
                count(*) FILTER (WHERE role = 'agent') AS agent_msgs,
                bool_or(payload->'products' IS NOT NULL AND jsonb_array_length(payload->'products') > 0) AS has_products
            ")
            ->groupBy('chat_session_id')
            ->get()
            ->keyBy('chat_session_id');

        $firsts = empty($ids) ? collect() : DB::table('chat_messages')
            ->whereIn('chat_session_id', $ids)
            ->where('role', 'user')
            ->selectRaw('DISTINCT ON (chat_session_id) chat_session_id, content')
            ->orderByRaw('chat_session_id, id')
            ->get()
            ->keyBy('chat_session_id');

        $list = $rows->map(function ($s) use ($agg, $firsts) {
            $a = $agg[$s->id] ?? null;
            $contact = json_decode((string) $s->contact, true) ?: [];
            $ctx = json_decode((string) $s->context, true) ?: [];

            $outcome = 'cevaplandi';
            if ($a && $a->has_products) {
                $outcome = 'urun_onerildi';
            }
            if ($s->status === 'handoff') {
                $outcome = 'handoff';
            }

            return [
                'id' => $s->id,
                'visitor' => $contact['name'] ?? ($contact['email'] ?? ($contact['phone'] ?? null)),
                'visitor_id' => $s->visitor_id,
                'contact' => $contact,
                'first_message' => mb_substr((string) ($firsts[$s->id]->content ?? ''), 0, 160),
                'detail' => $ctx['detail'] ?? null,
                'escalation_reason' => $ctx['escalation_reason'] ?? null,
                'user_messages' => (int) $s->user_message_count,
                'bot_messages' => (int) ($a->bot_msgs ?? 0),
                'agent_messages' => (int) ($a->agent_msgs ?? 0),
                'total_messages' => (int) ($a->total ?? 0),
                'status' => $s->status,
                'outcome' => $outcome,
                'page_url' => $s->page_url,
                'is_test' => (bool) $s->is_test,
                'started_at' => $s->created_at,
                'last_activity_at' => $s->last_activity_at,
                'conversation_id' => $s->conversation_id,
            ];
        })->values();

        return response()->json([
            'conversations' => $list,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
        ]);
    }

    /** GET /chat/rollout — kademeli açılım durumu (kota / kullanılan / kalan) */
    public function rollout(): JsonResponse
    {
        $tenantId = $this->tenantId();
        if (! $tenantId) {
            return response()->json(['error' => 'tenant_required'], 422);
        }

        $settings = \App\Services\Chat\TenantChatSettings::get($tenantId);
        $stats = \App\Services\Chat\ChatRollout::stats($tenantId, $settings);

        // Kohorttan kaç kişi gerçekten yazdı (kota tüketimi ≠ konuşma)
        $stats['wrote'] = (int) DB::table('chat_sessions')
            ->where('tenant_id', $tenantId)
            ->where('is_test', false)
            ->whereNotNull('visitor_id')
            ->where('user_message_count', '>', 0)
            ->distinct()
            ->count('visitor_id');

        $stats['enabled'] = $settings['enabled'];

        return response()->json($stats);
    }

    /**
     * GET /chat/product-analytics?range=7|30|90&test=0|1
     *
     * SOHBETTEN ÜRÜN HUNİSİ: gösterim → tıklama → sipariş.
     *
     * Gösterim `chat_messages.payload->products` dizisinden okunur (kartı kaç kez
     * kaç kişi gördü, kaçıncı sırada); tıklama ve sipariş `search_events`ten gelir
     * ve chat oturumuna `session_id = chat_sessions.session_key` ile bağlanır
     * (widget postEvent'te sid olarak oturum anahtarını yollar).
     *
     * ⚠️ Ciro = ATIF EDİLEN SİPARİŞİN TOPLAMI (yalnız tıklanan ürünün değil).
     * Atıf kuralı: aynı IP + tıklanan ürün siparişte + 24 saat içinde
     * (search:attribute-conversions).
     */
    public function productAnalytics(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        if (! $tenantId) {
            return response()->json(['error' => 'tenant_required'], 422);
        }

        $range = (int) $request->query('range', 30);
        if (! in_array($range, [7, 30, 90], true)) {
            $range = 30;
        }
        $since = now()->subDays($range)->format('Y-m-d H:i:s');
        // Pilotta test trafiği de görülmek istenebilir; varsayılan yalnız gerçek.
        $testSql = $request->boolean('test') ? '' : ' AND cs.is_test = false';

        // ── Gösterim (impression): bot kaç kez hangi kartı bastı, hangi sırada ──
        $imp = DB::select("
            SELECT (el.p->>'id')::bigint AS product_id,
                   count(*) AS impressions,
                   count(DISTINCT cs.id) AS sessions,
                   round(avg(el.ord)::numeric, 1) AS avg_position
            FROM chat_messages cm
            JOIN chat_sessions cs ON cs.id = cm.chat_session_id
            CROSS JOIN LATERAL jsonb_array_elements(cm.payload->'products') WITH ORDINALITY AS el(p, ord)
            WHERE cs.tenant_id = ? AND cm.created_at >= ?
              AND jsonb_typeof(cm.payload->'products') = 'array'
              AND (el.p->>'id') ~ '^[0-9]+$'
              {$testSql}
            GROUP BY 1
        ", [$tenantId, $since]);

        // ── Tıklama ve sipariş: chat oturumuna bağlı search_events ──
        $ev = DB::select("
            SELECT se.product_id,
                   count(*) FILTER (WHERE se.type = 'click') AS clicks,
                   count(DISTINCT se.session_id) FILTER (WHERE se.type = 'click') AS click_sessions,
                   count(DISTINCT se.platform_order_id) FILTER (WHERE se.type = 'conversion') AS orders,
                   coalesce(sum(se.value) FILTER (WHERE se.type = 'conversion'), 0) AS revenue
            FROM search_events se
            JOIN chat_sessions cs ON cs.session_key = se.session_id AND cs.tenant_id = ?
            WHERE se.tenant_id = ? AND se.created_at >= ? AND se.product_id IS NOT NULL
              {$testSql}
            GROUP BY 1
        ", [$tenantId, $tenantId, $since]);

        $byId = [];
        foreach ($imp as $r) {
            $byId[(int) $r->product_id] = [
                'id' => (int) $r->product_id,
                'impressions' => (int) $r->impressions,
                'sessions' => (int) $r->sessions,
                'avg_position' => (float) $r->avg_position,
                'clicks' => 0, 'click_sessions' => 0, 'orders' => 0, 'revenue' => 0.0,
            ];
        }
        foreach ($ev as $r) {
            $id = (int) $r->product_id;
            $byId[$id] ??= ['id' => $id, 'impressions' => 0, 'sessions' => 0, 'avg_position' => null,
                'clicks' => 0, 'click_sessions' => 0, 'orders' => 0, 'revenue' => 0.0];
            $byId[$id]['clicks'] = (int) $r->clicks;
            $byId[$id]['click_sessions'] = (int) $r->click_sessions;
            $byId[$id]['orders'] = (int) $r->orders;
            $byId[$id]['revenue'] = (float) $r->revenue;
        }

        // Ürün künyesi (ad/görsel/fiyat) tek sorguda
        $meta = DB::table('products')->where('tenant_id', $tenantId)
            ->whereIn('id', array_keys($byId) ?: [0])
            ->get(['id', 'name', 'url', 'price', 'discounted_price', 'images', 'stock_quantity'])
            ->keyBy('id');

        $products = [];
        foreach ($byId as $id => $row) {
            $m = $meta[$id] ?? null;
            $imgs = $m ? json_decode((string) $m->images, true) : null;
            $img = is_array($imgs) ? ($imgs[0] ?? null) : null;
            $img = is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : $img;

            $row['name'] = $m->name ?? ('#' . $id);
            $row['url'] = $m->url ?? null;
            $row['image'] = is_string($img) ? $img : null;
            $row['price'] = $m && $m->discounted_price > 0 && $m->discounted_price < $m->price
                ? (float) $m->discounted_price : (float) ($m->price ?? 0);
            $row['in_stock'] = $m ? ((float) $m->stock_quantity) > 0 : null;
            $row['ctr'] = $row['impressions'] > 0 ? round($row['clicks'] * 100 / $row['impressions'], 1) : 0.0;
            $products[] = $row;
        }
        usort($products, fn ($a, $b) => [$b['revenue'], $b['clicks'], $b['impressions']]
            <=> [$a['revenue'], $a['clicks'], $a['impressions']]);

        // ── Özet ──
        $totImp = array_sum(array_column($products, 'impressions'));
        $totClick = array_sum(array_column($products, 'clicks'));
        $totOrders = array_sum(array_column($products, 'orders'));
        $totRev = array_sum(array_column($products, 'revenue'));

        $funnel = DB::selectOne("
            SELECT
              count(DISTINCT cs.id) FILTER (WHERE cm.id IS NOT NULL) AS sessions_with_products,
              count(DISTINCT cs.id) AS sessions
            FROM chat_sessions cs
            LEFT JOIN chat_messages cm ON cm.chat_session_id = cs.id
                 AND jsonb_typeof(cm.payload->'products') = 'array'
                 AND jsonb_array_length(cm.payload->'products') > 0
            WHERE cs.tenant_id = ? AND cs.created_at >= ? {$testSql}
        ", [$tenantId, $since]);

        $clickSessions = (int) DB::selectOne("
            SELECT count(DISTINCT se.session_id) n
            FROM search_events se
            JOIN chat_sessions cs ON cs.session_key = se.session_id AND cs.tenant_id = ?
            WHERE se.tenant_id = ? AND se.type = 'click' AND se.created_at >= ? {$testSql}
        ", [$tenantId, $tenantId, $since])->n;

        // ── Sohbet aramaları: hangi istek kaç kart üretti, kaçı tıklandı ──
        $queries = DB::select("
            SELECT sq.normalized_query AS q,
                   count(DISTINCT sq.id) AS searches,
                   count(*) FILTER (WHERE se.type = 'click') AS clicks,
                   round(avg(sq.results_count)::numeric, 0) AS avg_results
            FROM search_queries sq
            LEFT JOIN search_events se ON se.search_query_id = sq.id
            WHERE sq.tenant_id = ? AND sq.source = 'chat' AND sq.created_at >= ?
              AND coalesce(sq.normalized_query, '') <> ''
              " . ($request->boolean('test') ? '' : ' AND sq.is_test = false') . "
            GROUP BY 1
            ORDER BY 2 DESC, 3 DESC
            LIMIT 25
        ", [$tenantId, $since]);

        // Katalog boşluğu: sohbette istenip HİÇ ürün çıkmayan aramalar
        $zero = DB::select("
            SELECT sq.normalized_query AS q, count(*) AS cnt
            FROM search_queries sq
            WHERE sq.tenant_id = ? AND sq.source = 'chat' AND sq.created_at >= ?
              AND sq.results_count = 0 AND coalesce(sq.normalized_query, '') <> ''
              " . ($request->boolean('test') ? '' : ' AND sq.is_test = false') . "
            GROUP BY 1 ORDER BY 2 DESC LIMIT 15
        ", [$tenantId, $since]);

        // ── Sohbetten gelen siparişler (kim ne aradı, neye tıkladı, ne aldı) ──
        $orders = DB::select("
            SELECT se.platform_order_id AS order_no, se.value AS amount, se.created_at AS order_date,
                   se.product_id, p.name AS product_name, p.url AS product_url,
                   sq.raw_query, sq.created_at AS searched_at, cs.id AS chat_session_id
            FROM search_events se
            JOIN chat_sessions cs ON cs.session_key = se.session_id AND cs.tenant_id = ?
            LEFT JOIN products p ON p.id = se.product_id
            LEFT JOIN search_queries sq ON sq.id = se.search_query_id
            WHERE se.tenant_id = ? AND se.type = 'conversion' AND se.created_at >= ? {$testSql}
            ORDER BY se.created_at DESC
            LIMIT 50
        ", [$tenantId, $tenantId, $since]);

        return response()->json([
            'range_days' => $range,
            'includes_test' => $request->boolean('test'),
            'summary' => [
                'impressions' => (int) $totImp,
                'distinct_products' => count($products),
                'clicks' => (int) $totClick,
                'ctr' => $totImp > 0 ? round($totClick * 100 / $totImp, 1) : 0.0,
                'orders' => (int) $totOrders,
                'revenue' => round($totRev, 2),
                'sessions' => (int) ($funnel->sessions ?? 0),
                'sessions_with_products' => (int) ($funnel->sessions_with_products ?? 0),
                'click_sessions' => $clickSessions,
            ],
            'products' => array_slice($products, 0, 100),
            'queries' => $queries,
            'zero_result_queries' => $zero,
            'orders' => $orders,
        ]);
    }

    /** POST /chat/rollout/reset — pilot kohortunu sıfırla (yeni tur test) */
    public function resetRollout(): JsonResponse
    {
        $tenantId = $this->tenantId();
        if (! $tenantId) {
            return response()->json(['error' => 'tenant_required'], 422);
        }

        $deleted = \App\Services\Chat\ChatRollout::reset($tenantId);

        return response()->json(['reset' => $deleted]);
    }
}
