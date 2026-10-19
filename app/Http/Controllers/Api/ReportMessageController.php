<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportMessageController extends Controller
{
    private const CATEGORIES = [
        'siparis_durumu' => ['label' => 'Sipariş Durumu', 'color' => '#3b82f6', 'keywords' => ['sipariş', 'siparişim', 'ne zaman gelecek', 'ne oldu']],
        'kargo_teslimat' => ['label' => 'Kargo & Teslimat', 'color' => '#8b5cf6', 'keywords' => ['kargo', 'teslimat', 'teslim', 'gönderi', 'ulaşmadı', 'gelmedi']],
        'iade_geri_odeme' => ['label' => 'İade & Geri Ödeme', 'color' => '#ef4444', 'keywords' => ['iade', 'geri ödeme', 'para iadesi', 'ücret iadesi', 'param']],
        'iptal' => ['label' => 'İptal', 'color' => '#f97316', 'keywords' => ['iptal', 'vazgeçtim', 'istemiyorum']],
        'urun_bilgisi' => ['label' => 'Ürün Bilgisi', 'color' => '#06b6d4', 'keywords' => ['beden', 'numara', 'ölçü', 'kalıp', 'renk', 'model', 'kumaş']],
        'fiyat_indirim' => ['label' => 'Fiyat & İndirim', 'color' => '#10b981', 'keywords' => ['fiyat', 'indirim', 'kampanya', 'ucuz', 'pahalı', 'kupon']],
        'stok' => ['label' => 'Stok', 'color' => '#f59e0b', 'keywords' => ['stok', 'tükendi', 'gelecek mi', 'temin']],
        'sikayet' => ['label' => 'Şikayet', 'color' => '#dc2626', 'keywords' => ['şikayet', 'memnun değil', 'kötü', 'berbat', 'rezalet']],
        'hasarli_defolu' => ['label' => 'Hasarlı / Defolu', 'color' => '#be185d', 'keywords' => ['hasar', 'hasarlı', 'defolu', 'kırık', 'yırtık', 'bozuk', 'kusurlu']],
        'yanlis_urun' => ['label' => 'Yanlış Ürün', 'color' => '#7c3aed', 'keywords' => ['yanlış', 'farklı geldi', 'hatalı', 'başka ürün']],
        'memnuniyet' => ['label' => 'Memnuniyet', 'color' => '#22c55e', 'keywords' => ['teşekkür', 'memnun', 'güzel', 'harika', 'süper', 'mükemmel', 'beğendim']],
    ];

    public function stats(Request $request): JsonResponse
    {
        $query = $this->baseQuery();
        $this->applyChannelFilter($query, $request);
        $periodQuery = $this->applyPeriod(clone $query, $request->period);

        $total = $query->count();
        $periodCount = $periodQuery->count();

        // Satisfaction & complaint rates for period
        $satisfactionKeywords = self::CATEGORIES['memnuniyet']['keywords'];
        $complaintKeywords = array_merge(
            self::CATEGORIES['sikayet']['keywords'],
            self::CATEGORIES['hasarli_defolu']['keywords'],
            self::CATEGORIES['yanlis_urun']['keywords']
        );

        $satisfactionCount = 0;
        $complaintCount = 0;

        if ($periodCount > 0) {
            $satisfactionBindings = [];
            $satisfactionConditions = array_map(function ($k) use (&$satisfactionBindings) {
                $satisfactionBindings[] = "%{$k}%";
                return "content ILIKE ?";
            }, $satisfactionKeywords);

            $complaintBindings = [];
            $complaintConditions = array_map(function ($k) use (&$complaintBindings) {
                $complaintBindings[] = "%{$k}%";
                return "content ILIKE ?";
            }, $complaintKeywords);

            $satisfactionCount = (clone $periodQuery)->whereRaw('(' . implode(' OR ', $satisfactionConditions) . ')', $satisfactionBindings)->count();
            $complaintCount = (clone $periodQuery)->whereRaw('(' . implode(' OR ', $complaintConditions) . ')', $complaintBindings)->count();
        }

        $days = $this->periodDays($request->period) ?: 365;
        $avgDaily = $days > 0 ? round($periodCount / $days, 1) : 0;

        return response()->json([
            'total_customer_messages' => $total,
            'period_customer_messages' => $periodCount,
            'satisfaction_rate' => $periodCount > 0 ? round(($satisfactionCount / $periodCount) * 100, 1) : 0,
            'complaint_rate' => $periodCount > 0 ? round(($complaintCount / $periodCount) * 100, 1) : 0,
            'avg_daily' => $avgDaily,
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $query = $this->baseQuery();
        $query = $this->applyPeriod($query, $request->period);
        $this->applyChannelFilter($query, $request);

        // Build single SQL with SUM(CASE) for each category (parameterized)
        $selects = [];
        $allBindings = [];
        foreach (self::CATEGORIES as $key => $cat) {
            $placeholders = array_map(function ($k) use (&$allBindings) {
                $allBindings[] = "%{$k}%";
                return "content ILIKE ?";
            }, $cat['keywords']);
            $where = implode(' OR ', $placeholders);
            $selects[] = "SUM(CASE WHEN ({$where}) THEN 1 ELSE 0 END) as {$key}";
        }

        $result = (clone $query)->selectRaw(implode(', ', $selects), $allBindings)->first();
        $totalPeriod = (clone $query)->count();

        $categories = [];
        $categorizedTotal = 0;
        foreach (self::CATEGORIES as $key => $cat) {
            $count = (int) ($result->$key ?? 0);
            $categorizedTotal += $count;
            $categories[] = [
                'key' => $key,
                'label' => $cat['label'],
                'color' => $cat['color'],
                'count' => $count,
                'percentage' => $totalPeriod > 0 ? round(($count / $totalPeriod) * 100, 1) : 0,
            ];
        }

        // Add "diger" category
        $otherCount = max(0, $totalPeriod - $categorizedTotal);
        $categories[] = [
            'key' => 'diger',
            'label' => 'Diğer',
            'color' => '#94a3b8',
            'count' => $otherCount,
            'percentage' => $totalPeriod > 0 ? round(($otherCount / $totalPeriod) * 100, 1) : 0,
        ];

        // Sort by count desc
        usort($categories, fn ($a, $b) => $b['count'] <=> $a['count']);

        return response()->json([
            'categories' => $categories,
            'total' => $totalPeriod,
        ]);
    }

    public function channels(Request $request): JsonResponse
    {
        $query = $this->baseQuery();
        $query = $this->applyPeriod($query, $request->period);
        $this->applyChannelFilter($query, $request);

        $channels = $query
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('channels', 'conversations.channel_id', '=', 'channels.id')
            ->select('channels.id', 'channels.name', 'channels.type')
            ->selectRaw('count(messages.id) as message_count')
            ->groupBy('channels.id', 'channels.name', 'channels.type')
            ->orderByDesc('message_count')
            ->get();

        $total = $channels->sum('message_count');

        return response()->json([
            'channels' => $channels->map(fn ($ch) => [
                'channel_id' => $ch->id,
                'channel_name' => $ch->name,
                'channel_type' => $ch->type,
                'message_count' => $ch->message_count,
                'percentage' => $total > 0 ? round(($ch->message_count / $total) * 100, 1) : 0,
            ]),
            'total' => $total,
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        $query = $this->baseQuery();
        $query = $this->applyPeriod($query, $request->period);
        $this->applyChannelFilter($query, $request);

        $trend = $query
            ->selectRaw("DATE(messages.created_at) as date")
            ->selectRaw('count(*) as count')
            ->groupByRaw("DATE(messages.created_at)")
            ->orderBy('date')
            ->get();

        return response()->json($trend);
    }

    public function byCategory(Request $request): JsonResponse
    {
        $categoryKey = $request->input('category');
        if (!$categoryKey) {
            return response()->json(['message' => 'category parameter required'], 422);
        }

        $query = $this->baseQuery();
        $query = $this->applyPeriod($query, $request->period);
        $this->applyChannelFilter($query, $request);

        if ($categoryKey === 'diger') {
            // Messages NOT matching any known category keywords
            $allKeywords = [];
            foreach (self::CATEGORIES as $cat) {
                $allKeywords = array_merge($allKeywords, $cat['keywords']);
            }
            $bindings = [];
            $conditions = array_map(function ($k) use (&$bindings) {
                $bindings[] = "%{$k}%";
                return "content ILIKE ?";
            }, $allKeywords);
            $query->whereRaw('NOT (' . implode(' OR ', $conditions) . ')', $bindings);
        } elseif (isset(self::CATEGORIES[$categoryKey])) {
            $keywords = self::CATEGORIES[$categoryKey]['keywords'];
            $bindings = [];
            $conditions = array_map(function ($k) use (&$bindings) {
                $bindings[] = "%{$k}%";
                return "content ILIKE ?";
            }, $keywords);
            $query->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
        } else {
            return response()->json(['message' => 'Invalid category'], 422);
        }

        $messages = $query
            ->with(['conversation:id,channel_id,customer_id', 'conversation.channel:id,name,type', 'conversation.customer:id,name'])
            ->select('messages.id', 'messages.content', 'messages.created_at', 'messages.conversation_id')
            ->orderByDesc('messages.created_at')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        $messages->getCollection()->transform(function ($msg) {
            return [
                'id' => $msg->id,
                'content' => $msg->content,
                'created_at' => $msg->created_at,
                'conversation_id' => $msg->conversation_id,
                'conversation' => $msg->conversation ? [
                    'id' => $msg->conversation->id,
                    'channel' => $msg->conversation->channel ? [
                        'name' => $msg->conversation->channel->name,
                        'type' => $msg->conversation->channel->type,
                    ] : null,
                ] : null,
                'customer_name' => $msg->conversation?->customer?->name,
            ];
        });

        return response()->json($messages);
    }

    private function applyChannelFilter($query, Request $request): void
    {
        if ($request->filled('channel_id')) {
            $query->whereHas('conversation', fn ($q) => $q->where('channel_id', (int) $request->channel_id));
        }
    }

    private function baseQuery()
    {
        return Message::where('sender_type', 'customer')
            ->whereNotNull('content')
            ->where('content', '!=', '');
    }

    private function applyPeriod($query, ?string $period)
    {
        $days = $this->periodDays($period);
        if ($days) {
            $query->where('messages.created_at', '>=', now()->subDays($days));
        }

        return $query;
    }

    private function periodDays(?string $period): ?int
    {
        return match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '180d' => 180,
            '365d' => 365,
            default => null,
        };
    }
}
