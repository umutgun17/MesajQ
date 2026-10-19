<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Effective tenant ID: super admin uses X-Tenant-Id header, normal user uses own tenant_id
        $tenantId = $user->tenant_id;
        if ($user->isSuperAdmin() && $request->hasHeader('X-Tenant-Id')) {
            $tenantId = (int) $request->header('X-Tenant-Id');
        }

        $channelStart = $this->periodToDate($request->input('channels_period', 'today'));
        $aiStart = $this->periodToDate($request->input('ai_period', 'today'));

        $agentPeriodStart = $this->periodToDate($request->input('agent_period', 'today'));

        $stats = [
            'overview' => $this->getOverview($tenantId),
            'channels' => $this->getChannelStats($tenantId, $channelStart),
            'recent_conversations' => $this->getRecentConversations(),
            'ai_performance' => $this->getAiPerformance($tenantId, $aiStart),
            'message_trend' => $this->getMessageTrend($tenantId),
            'hourly_distribution' => $this->getHourlyDistribution($tenantId),
            'agent_performance' => $this->getAgentPerformance($tenantId, $agentPeriodStart, $user),
        ];

        return response()->json($stats);
    }

    private function periodToDate(string $period): Carbon
    {
        $tz = 'Europe/Istanbul';

        return match ($period) {
            'yesterday' => Carbon::yesterday($tz)->utc(),
            '7d' => Carbon::today($tz)->subDays(7)->utc(),
            '30d' => Carbon::today($tz)->subDays(30)->utc(),
            default => Carbon::today($tz)->utc(),
        };
    }

    private function getOverview(?int $tenantId): array
    {
        $query = Conversation::query();

        return [
            'total_open' => (clone $query)->where('status', 'open')->count(),
            'total_pending' => (clone $query)->where('status', 'pending')->count(),
            'total_urgent' => (clone $query)->where('is_urgent', true)->where('status', '!=', 'closed')->count(),
            'total_unread' => (clone $query)->where('is_read', false)->count(),
        ];
    }

    private function getChannelStats(?int $tenantId, Carbon $startDate): array
    {
        $channels = Channel::where('is_active', true)
            ->where('type', '!=', 'ticimax-ecommerce')
            ->where('type', 'not like', '%-comment')
            ->get(['id', 'name', 'type', 'category']);

        // Agent-answered conversations
        $answeredByChannel = Message::where('messages.created_at', '>=', $startDate)
            ->where('messages.sender_type', 'agent')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->select('conversations.channel_id', DB::raw('COUNT(DISTINCT messages.conversation_id) as cnt'))
            ->groupBy('conversations.channel_id')
            ->pluck('cnt', 'conversations.channel_id');

        // Total conversations with messages by channel
        $totalByChannel = Message::where('messages.created_at', '>=', $startDate)
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->select('conversations.channel_id', DB::raw('COUNT(DISTINCT messages.conversation_id) as cnt'))
            ->groupBy('conversations.channel_id')
            ->pluck('cnt', 'conversations.channel_id');

        // AI usage by channel
        $aiUsedByChannel = Message::where('messages.created_at', '>=', $startDate)
            ->where('messages.is_ai_generated', true)
            ->where('messages.sender_type', 'agent')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->select('conversations.channel_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('conversations.channel_id')
            ->pluck('cnt', 'conversations.channel_id');

        // AI approved (not edited) by channel
        $aiApprovedByChannel = Message::where('messages.created_at', '>=', $startDate)
            ->where('messages.is_ai_generated', true)
            ->where('messages.sender_type', 'agent')
            ->whereColumn('messages.content', 'messages.ai_original_content')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->select('conversations.channel_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('conversations.channel_id')
            ->pluck('cnt', 'conversations.channel_id');

        // Avg response time: customer message → next non-customer reply
        $tenantFilter = $tenantId ? 'AND cust_msg.tenant_id = ?' : '';
        $params = $tenantId ? [$startDate, $tenantId] : [$startDate];

        $avgResponseByChannel = DB::select("
            SELECT c.channel_id,
                   AVG(EXTRACT(EPOCH FROM (reply.created_at - cust_msg.created_at))) as avg_seconds
            FROM messages cust_msg
            JOIN conversations c ON cust_msg.conversation_id = c.id
            JOIN LATERAL (
                SELECT created_at FROM messages
                WHERE conversation_id = cust_msg.conversation_id
                  AND sender_type != 'customer'
                  AND created_at > cust_msg.created_at
                ORDER BY created_at ASC
                LIMIT 1
            ) reply ON true
            WHERE cust_msg.sender_type = 'customer'
              AND cust_msg.created_at >= ?
              {$tenantFilter}
            GROUP BY c.channel_id
        ", $params);

        $avgResponseMap = collect($avgResponseByChannel)->pluck('avg_seconds', 'channel_id');

        $result = $channels->map(function ($channel) use ($totalByChannel, $answeredByChannel, $aiUsedByChannel, $aiApprovedByChannel, $avgResponseMap) {
            $total = $totalByChannel[$channel->id] ?? 0;
            $answered = $answeredByChannel[$channel->id] ?? 0;

            return [
                'channel' => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type,
                    'category' => $channel->category,
                ],
                'total_conversations' => $total,
                'answered' => $answered,
                'unanswered' => max(0, $total - $answered),
                'avg_response_time_seconds' => isset($avgResponseMap[$channel->id]) ? round($avgResponseMap[$channel->id]) : null,
                'ai_used_count' => $aiUsedByChannel[$channel->id] ?? 0,
                'ai_approved_count' => $aiApprovedByChannel[$channel->id] ?? 0,
            ];
        });

        return $result->values()->toArray();
    }

    private function getRecentConversations(): array
    {
        return Conversation::with(['customer:id,name,avatar', 'channel:id,name,type', 'assignedUser:id,name'])
            ->where('status', '!=', 'closed')
            ->orderByDesc('last_message_at')
            ->limit(10)
            ->get()
            ->map(fn ($conv) => [
                'id' => $conv->id,
                'type' => $conv->type,
                'status' => $conv->status,
                'is_urgent' => $conv->is_urgent,
                'is_read' => $conv->is_read,
                'unread_count' => $conv->unread_count,
                'last_message_at' => $conv->last_message_at?->toISOString(),
                'customer' => $conv->customer ? ['id' => $conv->customer->id, 'name' => $conv->customer->name, 'avatar' => $conv->customer->avatar] : null,
                'channel' => $conv->channel ? ['id' => $conv->channel->id, 'name' => $conv->channel->name, 'type' => $conv->channel->type] : null,
                'assigned_to' => $conv->assignedUser ? ['id' => $conv->assignedUser->id, 'name' => $conv->assignedUser->name] : null,
            ])
            ->toArray();
    }

    private function getAiPerformance(?int $tenantId, Carbon $startDate): array
    {
        // Total agent messages in period
        $totalAgentMessages = Message::where('sender_type', 'agent')
            ->where('created_at', '>=', $startDate)
            ->count();

        // AI-generated messages sent by agents
        $aiMessages = Message::where('sender_type', 'agent')
            ->where('is_ai_generated', true)
            ->where('created_at', '>=', $startDate);

        $totalAiMessages = (clone $aiMessages)->count();

        // Auto-sent (SendAutoReply — no human involvement, marked in metadata)
        $autoSent = (clone $aiMessages)
            ->whereJsonContains('metadata->auto_reply', true)
            ->count();

        // Manually sent AI messages (human was involved)
        $manualAiMessages = $totalAiMessages - $autoSent;

        // Directly approved (content matches ai_original_content, human sent without editing)
        $directlyApproved = (clone $aiMessages)
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhereJsonDoesntContain('metadata->auto_reply', true);
            })
            ->whereNotNull('ai_original_content')
            ->whereColumn('content', 'ai_original_content')
            ->count();

        // Edited and sent (human modified AI suggestion before sending)
        $editedAndSent = $manualAiMessages - $directlyApproved;

        // Avg reply time: customer message → first agent reply
        $tenantFilter = $tenantId ? 'AND cust_msg.tenant_id = ?' : '';
        $params = $tenantId ? [$startDate, $tenantId] : [$startDate];

        $avgReplyTime = DB::select("
            SELECT AVG(EXTRACT(EPOCH FROM (reply.created_at - cust_msg.created_at))) as avg_seconds
            FROM messages cust_msg
            JOIN LATERAL (
                SELECT created_at FROM messages
                WHERE conversation_id = cust_msg.conversation_id
                  AND sender_type = 'agent'
                  AND created_at > cust_msg.created_at
                ORDER BY created_at ASC
                LIMIT 1
            ) reply ON true
            WHERE cust_msg.sender_type = 'customer'
              AND cust_msg.created_at >= ?
              {$tenantFilter}
        ", $params);

        $avgSeconds = $avgReplyTime[0]->avg_seconds ?? null;

        return [
            'total_agent_messages' => $totalAgentMessages,
            'total_ai_messages' => $totalAiMessages,
            'auto_sent' => $autoSent,
            'directly_approved' => $directlyApproved,
            'edited_and_sent' => $editedAndSent,
            'ai_usage_rate' => $totalAgentMessages > 0
                ? round(($totalAiMessages / $totalAgentMessages) * 100, 1)
                : 0,
            'approval_rate' => $totalAiMessages > 0
                ? round(($directlyApproved / $totalAiMessages) * 100, 1)
                : 0,
            'avg_reply_time_seconds' => $avgSeconds ? (int) round((float) $avgSeconds) : null,
        ];
    }

    private function getMessageTrend(?int $tenantId): array
    {
        $since = Carbon::today('Europe/Istanbul')->subDays(30)->utc();

        $query = DB::table('messages')
            ->where('sender_type', 'customer')
            ->where('created_at', '>=', $since);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->select(
                DB::raw("DATE(created_at + interval '3 hours') as date"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw("DATE(created_at + interval '3 hours')"))
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->count])
            ->toArray();
    }

    private function getHourlyDistribution(?int $tenantId): array
    {
        $todayStart = Carbon::today('Europe/Istanbul')->utc();

        $query = DB::table('messages')
            ->where('sender_type', 'agent')
            ->where('created_at', '>=', $todayStart);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $rows = $query
            ->select(
                DB::raw("EXTRACT(HOUR FROM created_at + interval '3 hours')::int as hour"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw("EXTRACT(HOUR FROM created_at + interval '3 hours')"))
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = [
                'hour' => $h,
                'count' => (int) ($rows[$h]->count ?? 0),
            ];
        }

        return $result;
    }

    private function getAgentPerformance(?int $tenantId, Carbon $startDate, User $currentUser): array
    {
        $isAdmin = $currentUser->isSuperAdmin() || $currentUser->isAdmin();

        // Get relevant user IDs
        if ($currentUser->isSuperAdmin()) {
            $selectedTenantId = request()->hasHeader('X-Tenant-Id')
                ? (int) request()->header('X-Tenant-Id')
                : null;

            $query = User::where('status', 'active');
            if ($selectedTenantId) {
                $query->where('tenant_id', $selectedTenantId);
            } else {
                $query->whereNotNull('tenant_id');
            }
            $userIds = $query->pluck('id');
        } elseif ($isAdmin) {
            $userIds = User::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->pluck('id');
        } else {
            $userIds = collect([$currentUser->id]);
        }

        if ($userIds->isEmpty()) {
            return [];
        }

        // Total agent messages per user
        $totalMessages = Message::where('sender_type', 'agent')
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->select('sender_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sender_id')
            ->pluck('cnt', 'sender_id');

        // AI-generated messages per user
        $aiMessages = Message::where('sender_type', 'agent')
            ->where('is_ai_generated', true)
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->select('sender_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sender_id')
            ->pluck('cnt', 'sender_id');

        // Directly approved (AI used, content unchanged) per user
        $directlyApproved = Message::where('sender_type', 'agent')
            ->where('is_ai_generated', true)
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->whereNotNull('ai_original_content')
            ->whereColumn('content', 'ai_original_content')
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhereJsonDoesntContain('metadata->auto_reply', true);
            })
            ->select('sender_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sender_id')
            ->pluck('cnt', 'sender_id');

        // Unique conversations answered per user
        $conversationsAnswered = Message::where('sender_type', 'agent')
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->select('sender_id', DB::raw('COUNT(DISTINCT conversation_id) as cnt'))
            ->groupBy('sender_id')
            ->pluck('cnt', 'sender_id');

        // Total handling time per user (actual working time tracked from frontend)
        $totalHandlingTimes = Message::where('sender_type', 'agent')
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->whereNotNull('handling_time_seconds')
            ->where('handling_time_seconds', '>', 0)
            ->where('handling_time_seconds', '<=', 3600) // cap at 1h to filter outliers
            ->select('sender_id', DB::raw('SUM(handling_time_seconds) as total_seconds'))
            ->groupBy('sender_id')
            ->pluck('total_seconds', 'sender_id');

        // Avg reply time per user (customer message → first agent reply)
        $agentTenantFilter = $tenantId ? 'AND cust_msg.tenant_id = ?' : '';
        $agentParams = $tenantId ? [$startDate, $tenantId] : [$startDate];

        $avgReplyTimes = DB::select("
            SELECT reply.sender_id,
                   AVG(EXTRACT(EPOCH FROM (reply.created_at - cust_msg.created_at))) as avg_seconds
            FROM messages cust_msg
            JOIN LATERAL (
                SELECT created_at, sender_id FROM messages
                WHERE conversation_id = cust_msg.conversation_id
                  AND sender_type = 'agent'
                  AND sender_id IN (" . $userIds->implode(',') . ")
                  AND created_at > cust_msg.created_at
                ORDER BY created_at ASC
                LIMIT 1
            ) reply ON true
            WHERE cust_msg.sender_type = 'customer'
              AND cust_msg.created_at >= ?
              {$agentTenantFilter}
            GROUP BY reply.sender_id
        ", $agentParams);

        $avgReplyMap = collect($avgReplyTimes)->pluck('avg_seconds', 'sender_id');

        // Reopened conversations per user (resolved/closed → customer sent new message → reopened)
        // A conversation is "reopened" if it was resolved/closed by an agent but later got a new customer message
        $reopenedByUser = DB::table('messages as agent_msg')
            ->join('conversations as c', 'agent_msg.conversation_id', '=', 'c.id')
            ->where('agent_msg.sender_type', 'agent')
            ->where('agent_msg.created_at', '>=', $startDate)
            ->whereIn('agent_msg.sender_id', $userIds)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages as followup')
                    ->whereColumn('followup.conversation_id', 'agent_msg.conversation_id')
                    ->where('followup.sender_type', 'customer')
                    ->whereColumn('followup.created_at', '>', 'agent_msg.created_at');
            })
            ->select('agent_msg.sender_id', DB::raw('COUNT(DISTINCT agent_msg.conversation_id) as cnt'))
            ->groupBy('agent_msg.sender_id')
            ->pluck('cnt', 'agent_msg.sender_id');

        // First Contact Resolution: conversations where agent replied only once and conversation is resolved/closed
        $fcrByUser = DB::table('messages')
            ->where('sender_type', 'agent')
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->select('sender_id', 'conversation_id', DB::raw('COUNT(*) as msg_count'))
            ->groupBy('sender_id', 'conversation_id')
            ->having(DB::raw('COUNT(*)'), '=', 1)
            ->get()
            ->groupBy('sender_id')
            ->map(fn ($rows) => $rows->count());

        // Penalty points per user (sum of penalty_points on their messages in period)
        $penaltyByUser = Message::where('sender_type', 'agent')
            ->where('created_at', '>=', $startDate)
            ->whereIn('sender_id', $userIds)
            ->where('penalty_points', '>', 0)
            ->select('sender_id', DB::raw('SUM(penalty_points) as total_penalty'))
            ->groupBy('sender_id')
            ->pluck('total_penalty', 'sender_id');

        // Calculate period hours for speed calculation
        $now = Carbon::now('Europe/Istanbul');
        $periodStart = $startDate->copy()->setTimezone('Europe/Istanbul');
        $periodHours = max(1, $periodStart->diffInHours($now));

        // Build result
        $users = User::with('tenant:id,name')->whereIn('id', $userIds)->get(['id', 'name', 'avatar', 'role', 'tenant_id']);

        // Collect all scores to normalize
        $rawResults = $users->map(function ($user) use ($totalMessages, $aiMessages, $directlyApproved, $conversationsAnswered, $totalHandlingTimes, $avgReplyMap, $periodHours, $reopenedByUser, $fcrByUser, $penaltyByUser) {
            $total = $totalMessages[$user->id] ?? 0;
            $ai = $aiMessages[$user->id] ?? 0;
            $approved = $directlyApproved[$user->id] ?? 0;
            $edited = max(0, $ai - $approved);
            $manual = max(0, $total - $ai);
            $totalHandling = isset($totalHandlingTimes[$user->id]) ? (int) round((float) $totalHandlingTimes[$user->id]) : null;
            $avgReply = isset($avgReplyMap[$user->id]) ? (int) round((float) $avgReplyMap[$user->id]) : null;
            $speed = $total > 0 ? round($total / $periodHours, 1) : 0;
            $convsAnswered = $conversationsAnswered[$user->id] ?? 0;
            $reopened = $reopenedByUser[$user->id] ?? 0;
            $fcr = $fcrByUser[$user->id] ?? 0;
            $penalty = (int) ($penaltyByUser[$user->id] ?? 0);

            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'role' => $user->role,
                    'tenant_name' => $user->tenant?->name,
                ],
                'total_messages' => $total,
                'conversations_answered' => $convsAnswered,
                'ai_used' => $ai,
                'directly_approved' => $approved,
                'edited_and_sent' => $edited,
                'manual' => $manual,
                'total_handling_time_seconds' => $totalHandling,
                'avg_reply_time_seconds' => $avgReply,
                'speed_per_hour' => $speed,
                'penalty_points' => $penalty,
                '_reopened' => $reopened,
                '_fcr' => $fcr,
            ];
        });

        // Calculate performance score using peer-relative normalization
        // Only consider users with messages for normalization
        $activeResults = $rawResults->filter(fn ($r) => $r['total_messages'] > 0);

        if ($activeResults->isEmpty()) {
            return $rawResults->map(function ($row) {
                $row['performance_score'] = null;
                unset($row['_reopened'], $row['_fcr']);
                return $row;
            })->sortByDesc('total_messages')->values()->toArray();
        }

        // Use sqrt normalization to reduce outlier dominance (Evin 507 vs Ezgi 73)
        $allTotalsSqrt = $activeResults->pluck('total_messages')->map(fn ($v) => sqrt($v));
        $maxMsgSqrt = max($allTotalsSqrt->max(), 1);
        $allConvsSqrt = $activeResults->pluck('conversations_answered')->map(fn ($v) => sqrt($v));
        $maxConvsSqrt = max($allConvsSqrt->max(), 1);

        $allSpeed = $activeResults->pluck('speed_per_hour');
        $maxSpeed = max($allSpeed->max(), 0.1);

        return $rawResults->map(function ($row) use ($maxMsgSqrt, $maxConvsSqrt, $maxSpeed) {
            $total = $row['total_messages'];
            $convsAnswered = $row['conversations_answered'];
            $avgReply = $row['avg_reply_time_seconds'];
            $speed = $row['speed_per_hour'];
            $reopened = $row['_reopened'];
            $fcr = $row['_fcr'];
            $ai = $row['ai_used'];

            // No messages → no score
            if ($total === 0) {
                $row['performance_score'] = null;
                unset($row['_reopened'], $row['_fcr']);
                return $row;
            }

            // 1) Quantity (35%): sqrt-normalized to reduce outlier dominance
            $quantityScore = ((sqrt($total) / $maxMsgSqrt) * 0.6 + (sqrt($convsAnswered) / $maxConvsSqrt) * 0.4) * 100;

            // 2) Speed (30%): logarithmic absolute scale
            //    5 min (300s) = 100 pts, 8h (28800s) = 10 pts, proportional in between
            //    No reply data → 0
            if ($avgReply !== null && $avgReply > 0) {
                $minRef = 300;   // 5 min → 100 points
                $maxRef = 28800; // 8 hours → 10 points
                if ($avgReply <= $minRef) {
                    $speedScore = 100;
                } elseif ($avgReply >= $maxRef) {
                    $speedScore = 10;
                } else {
                    // Logarithmic interpolation: smooth curve between 100 and 10
                    $logMin = log($minRef);
                    $logMax = log($maxRef);
                    $logVal = log($avgReply);
                    $speedScore = 100 - (($logVal - $logMin) / ($logMax - $logMin)) * 90;
                }
            } else {
                $speedScore = 0; // no data = no speed credit
            }

            // 3) Quality (20%): based on reopened rate and FCR
            $reopenedRate = $convsAnswered > 0 ? ($reopened / $convsAnswered) : 0;
            $fcrRate = $convsAnswered > 0 ? ($fcr / $convsAnswered) : 0;
            // Low reopen = good (invert), high FCR = good
            $qualityScore = ((1 - min($reopenedRate, 1)) * 0.5 + $fcrRate * 0.5) * 100;

            // 4) Efficiency (15%): AI usage rate + speed per hour relative to peers
            $aiRate = $total > 0 ? ($ai / $total) : 0;
            $relativeSpeed = $speed / $maxSpeed;
            $efficiencyScore = ($aiRate * 0.5 + $relativeSpeed * 0.5) * 100;

            // Weighted total
            $score = ($quantityScore * 0.35) + ($speedScore * 0.30) + ($qualityScore * 0.20) + ($efficiencyScore * 0.15);

            // 5) Penalty deduction: each penalty point costs 3 score points
            $penalty = $row['penalty_points'];
            if ($penalty > 0) {
                $score = max(0, $score - ($penalty * 3));
            }

            $row['performance_score'] = (int) round(min(100, max(0, $score)));

            unset($row['_reopened'], $row['_fcr']);
            return $row;
        })->sortByDesc('total_messages')->values()->toArray();
    }

    public function penalties(Request $request): JsonResponse
    {
        $user = $request->user();
        $agentId = $request->input('agent_id');
        $period = $request->input('period', 'today');
        $startDate = $this->periodToDate($period);

        $query = Message::withoutGlobalScopes()
            ->where('sender_type', 'agent')
            ->where('penalty_points', '>', 0)
            ->where('created_at', '>=', $startDate)
            ->with(['sender:id,name,avatar', 'conversation:id,channel_id', 'conversation.channel:id,name,type', 'penalizedByUser:id,name']);

        if ($agentId) {
            $query->where('sender_id', (int) $agentId);
        }

        // Tenant filtering
        if (! $user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        } elseif ($request->hasHeader('X-Tenant-Id')) {
            $query->where('tenant_id', (int) $request->header('X-Tenant-Id'));
        }

        $messages = $query->orderByDesc('created_at')->limit(100)->get();

        return response()->json($messages->map(fn ($m) => [
            'id' => $m->id,
            'content' => $m->content,
            'created_at' => $m->created_at->toISOString(),
            'conversation_id' => $m->conversation_id,
            'sender' => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
            'channel' => $m->conversation?->channel ? ['name' => $m->conversation->channel->name, 'type' => $m->conversation->channel->type] : null,
            'penalized_by' => $m->penalizedByUser ? ['id' => $m->penalizedByUser->id, 'name' => $m->penalizedByUser->name] : null,
        ]));
    }
}
