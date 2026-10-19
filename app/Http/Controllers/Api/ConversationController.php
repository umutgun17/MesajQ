<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $eagerLoads = [
            'customer:id,name,phone,platform_id,avatar,platform_type,metadata',
            'channel:id,name,type,category',
            'assignedUser:id,name,avatar',
            'latestMessage',
        ];

        // Comment conversations need comments for list view (status badge + preview)
        if ($request->type === 'comment') {
            $eagerLoads['comments'] = fn ($q) => $q->select('id', 'conversation_id', 'content', 'status')->orderByDesc('created_at');
        }

        $query = Conversation::with($eagerLoads);

        // View-based filtering (tab system)
        if ($request->filled('view')) {
            if ($request->type === 'comment') {
                // Comment conversations: filter by Comment.status (source of truth)
                match ($request->view) {
                    'waiting' => $query->whereHas('comments', fn ($q) => $q->where('status', 'pending')),
                    'answered' => $query->whereDoesntHave('comments', fn ($q) => $q->where('status', 'pending')),
                    'urgent' => $query->where('is_urgent', true),
                    default => null,
                };
            } else {
                match ($request->view) {
                    'waiting' => $query->where('status', 'open'),
                    'answered' => $query->whereIn('status', ['resolved', 'pending', 'closed']),
                    'urgent' => $query->where('is_urgent', true),
                    default => null,
                };
            }
        }

        // Filtreler
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('channel_ids')) {
            $ids = array_filter(explode(',', $request->channel_ids));
            if (!empty($ids)) {
                $query->whereIn('channel_id', $ids);
            }
        } elseif ($request->filled('channel_id')) {
            $query->where('channel_id', $request->channel_id);
        }

        // Tarih filtresi (validated)
        if ($request->filled('date_from') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date_from)) {
            $query->where('last_message_at', '>=', $request->date_from . ' 00:00:00');
        }

        if ($request->filled('date_to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date_to)) {
            $query->where('last_message_at', '<=', $request->date_to . ' 23:59:59');
        }

        if ($request->filled('is_urgent')) {
            $query->where('is_urgent', filter_var($request->is_urgent, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_read')) {
            $query->where('is_read', filter_var($request->is_read, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('assigned_to')) {
            $request->assigned_to === 'unassigned'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            // Telefon araması: rakamları ayıkla, baştaki 0'ları at (05XX girişi DB'deki
            // 905XX biçimini bulsun). WhatsApp'ta numara customers.platform_id'de durur.
            $phoneDigits = preg_replace('/\D+/', '', $request->search);
            $phoneNeedle = strlen($phoneDigits) >= 4 ? ltrim($phoneDigits, '0') : '';
            // IG/WA kullanıcı adı customers.metadata'da durur; "@kullanici" girişi de bulsun.
            $username = ltrim($search, '@');
            $query->where(function ($q) use ($search, $phoneNeedle, $username) {
                $q->whereHas('customer', function ($cq) use ($search, $phoneNeedle, $username) {
                    $cq->where('name', 'ilike', "%{$search}%");
                    if ($username !== '') {
                        $cq->orWhereRaw("metadata->>'instagram_username' ilike ?", ["%{$username}%"])
                           ->orWhereRaw("metadata->>'wa_username' ilike ?", ["%{$username}%"]);
                    }
                    if (strlen($phoneNeedle) >= 3) {
                        $cq->orWhere('phone', 'like', "%{$phoneNeedle}%")
                           ->orWhere('platform_id', 'like', "%{$phoneNeedle}%");
                    }
                })
                  ->orWhere('platform_conversation_id', 'ilike', "%{$search}%")
                  ->orWhereHas('messages', fn ($mq) => $mq->where('content', 'ilike', "%{$search}%"));
            });
        }

        // Filter conversations that have penalized messages by a specific agent
        if ($request->filled('penalty_agent_id')) {
            $penaltyAgentId = (int) $request->penalty_agent_id;
            $query->whereHas('messages', fn ($mq) => $mq
                ->where('sender_type', 'agent')
                ->where('sender_id', $penaltyAgentId)
                ->where('penalty_points', '>', 0)
            );
        }

        $this->applyLastAgentFilter($query, $request);

        $allowedSortFields = ['created_at', 'last_message_at', 'status', 'is_urgent', 'is_read'];
        $sortField = in_array($request->input('sort'), $allowedSortFields)
            ? $request->input('sort')
            : 'last_message_at';
        $sortDir = in_array($request->input('direction'), ['asc', 'desc'])
            ? $request->input('direction')
            : 'desc';
        $query->orderBy($sortField, $sortDir);

        $conversations = $query->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json($conversations);
    }

    public function counts(Request $request): JsonResponse
    {
        $base = Conversation::query();

        if ($request->filled('type')) {
            $base->where('type', $request->type);
        }

        if ($request->filled('channel_ids')) {
            $ids = array_filter(explode(',', $request->channel_ids));
            if (!empty($ids)) {
                $base->whereIn('channel_id', $ids);
            }
        } elseif ($request->filled('channel_id')) {
            $base->where('channel_id', $request->channel_id);
        }

        if ($request->filled('date_from') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date_from)) {
            $base->where('last_message_at', '>=', $request->date_from . ' 00:00:00');
        }

        if ($request->filled('date_to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date_to)) {
            $base->where('last_message_at', '<=', $request->date_to . ' 23:59:59');
        }

        $this->applyLastAgentFilter($base, $request);

        // Comment conversations: count by Comment.status (source of truth)
        if ($request->type === 'comment') {
            $counts = (object) [
                'total'    => (clone $base)->count(),
                'unread'   => (clone $base)->where('is_read', false)->count(),
                'waiting'  => (clone $base)->whereHas('comments', fn ($q) => $q->where('status', 'pending'))->count(),
                'answered' => (clone $base)->whereDoesntHave('comments', fn ($q) => $q->where('status', 'pending'))->count(),
                'urgent'   => (clone $base)->where('is_urgent', true)->count(),
            ];
        } else {
            $counts = $base->selectRaw("
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE is_read = false) AS unread,
                COUNT(*) FILTER (WHERE status = 'open') AS waiting,
                COUNT(*) FILTER (WHERE status IN ('resolved', 'pending', 'closed')) AS answered,
                COUNT(*) FILTER (WHERE is_urgent = true) AS urgent
            ")->first();
        }

        return response()->json($counts);
    }

    /**
     * Personel filtresi (yalnız yönetici): konuşmaya EN SON yazan personel seçilen
     * kişiyse konuşma listelenir. Otomasyon/entegrasyon mesajları (sender_id boş)
     * "son yazan" sayılmaz; müşterinin sonradan yazması da sonucu değiştirmez.
     */
    private function applyLastAgentFilter($query, Request $request): void
    {
        if (! $request->filled('last_agent_id')) {
            return;
        }
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return;
        }

        $query->whereRaw(
            "EXISTS (
                SELECT 1 FROM messages m
                WHERE m.conversation_id = conversations.id
                  AND m.sender_type = 'agent'
                  AND m.sender_id = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM messages m2
                      WHERE m2.conversation_id = m.conversation_id
                        AND m2.sender_type = 'agent'
                        AND m2.sender_id IS NOT NULL
                        AND m2.sender_id <> m.sender_id
                        AND (m2.created_at, m2.id) > (m.created_at, m.id)
                  )
            )",
            [(int) $request->last_agent_id]
        );
    }

    /**
     * Personel filtresi için liste: bu firmada mesaj yazmış kullanıcılar.
     */
    public function agents(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([]);
        }

        // Message tenant kapsamlı olduğundan yalnız bu firmanın yazarları gelir
        $senderIds = Message::query()
            ->where('sender_type', 'agent')
            ->whereNotNull('sender_id')
            ->distinct()
            ->pluck('sender_id');

        // Firma yöneticisi yalnız kendi firmasının kullanıcılarını görür; firmada
        // destek amaçlı yazmış Yunbi süper adminleri yalnız süper admine listelenir
        $agents = User::whereIn('id', $senderIds)
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->orderBy('name')
            ->get(['id', 'name', 'avatar', 'status']);

        return response()->json($agents);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load([
            'customer',
            'channel:id,name,type,category',
            'assignedUser:id,name,avatar',
            'messages' => fn ($q) => $q->with('sender:id,name,avatar')->orderBy('created_at'),
            'comments' => fn ($q) => $q->with('replies.user:id,name,avatar')->orderByDesc('created_at'),
        ]);

        // WhatsApp sohbetinde aynı numaraya giden otomasyon mesajlarını (WA+SMS) zaman
        // sırasıyla araya kat — temsilci, müşterinin NEYE cevap verdiğini görsün
        // (ör. "teşekkür ederiz" → üstünde gönderilen kupon/sipariş bildirimi).
        if (($conversation->channel->type ?? null) === 'whatsapp') {
            $digits = preg_replace('/\D/', '', (string) ($conversation->customer->platform_id ?? ''));
            $p10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;
            if (strlen($p10) === 10) {
                $automation = \App\Models\MarketingMessage::withoutGlobalScopes()
                    ->where('tenant_id', $conversation->tenant_id)
                    ->whereIn('channel', ['whatsapp', 'sms'])
                    ->where('status', 'sent')
                    ->whereRaw("right(regexp_replace(recipient, '\\D', '', 'g'), 10) = ?", [$p10])
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn ($m) => [
                        'id' => 'mkt_' . $m->id,
                        'conversation_id' => $conversation->id,
                        'sender_type' => 'automation',
                        'sender_id' => null,
                        'sender' => null,
                        'content' => $m->body ?: ($m->subject ?: '[Otomasyon mesajı]'),
                        'is_ai_generated' => false,
                        'created_at' => $m->created_at?->toISOString(),
                        'updated_at' => $m->created_at?->toISOString(),
                        'metadata' => [
                            'automation' => true,
                            'automation_source' => $m->source,
                            'automation_channel' => $m->channel,
                            'marketing_message_id' => $m->id,
                            'coupon_code' => $m->meta['coupon_code'] ?? null,
                            'order_no' => $m->meta['order_no'] ?? null,
                        ],
                    ]);

                if ($automation->isNotEmpty()) {
                    $data = $conversation->toArray();
                    $data['messages'] = collect($data['messages'] ?? [])
                        ->concat($automation)
                        ->sortBy(fn ($m) => $m['created_at'] ?? '')
                        ->values()
                        ->all();

                    return response()->json($data);
                }
            }
        }

        return response()->json($conversation);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $validated = $request->validate([
            'status' => 'sometimes|in:open,pending,resolved,closed',
            'is_urgent' => 'sometimes|boolean',
            'is_read' => 'sometimes|boolean',
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('users', 'id')->when($tenantId, fn ($rule) => $rule->where('tenant_id', $tenantId))],
        ]);

        $conversation->update($validated);

        return response()->json($conversation->fresh(['assignedUser:id,name,avatar']));
    }

    public function markAsRead(Conversation $conversation): JsonResponse
    {
        // Sadece panel içi okundu durumu güncellenir.
        // WhatsApp'a okundu bilgisi (mavi tik) BİLEREK gönderilmiyor — kullanıcı kararı.
        $conversation->update([
            'is_read' => true,
            'unread_count' => 0,
        ]);

        return response()->json(['message' => 'Okundu olarak işaretlendi.']);
    }

    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $validated = $request->validate([
            'user_id' => ['nullable', Rule::exists('users', 'id')->when($tenantId, fn ($rule) => $rule->where('tenant_id', $tenantId))],
        ]);

        $conversation->update(['assigned_to' => $validated['user_id']]);

        return response()->json($conversation->fresh(['assignedUser:id,name,avatar']));
    }
}
