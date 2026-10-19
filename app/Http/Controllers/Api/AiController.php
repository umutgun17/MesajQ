<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiResponseLog;
use App\Models\AiTrainingData;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Services\Ai\ClaudeKeyPolicy;
use App\Services\ClaudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    // ── AI Cevap Üretme ──

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'conversation_id' => 'required_without:comment_id|nullable|exists:conversations,id',
            'comment_id' => 'required_without:conversation_id|nullable|exists:comments,id',
        ]);

        $startTime = microtime(true);
        $prompt = '';
        $conversationId = $request->conversation_id;
        $commentId = $request->comment_id;
        $conversationHistory = [];

        // Get the customer's last message or comment content
        if ($conversationId) {
            $conversation = Conversation::with(['messages', 'customer', 'channel'])->findOrFail($conversationId);
            $lastCustomerMessage = $conversation->messages
                ->where('sender_type', 'customer')
                ->filter(fn ($m) => trim($m->content ?? '') !== '')
                ->sortByDesc('id')
                ->first();
            $prompt = $lastCustomerMessage?->content ?? '';

            // Build conversation history for Claude
            $conversationHistory = $conversation->messages
                ->sortBy('created_at')
                ->map(fn ($m) => [
                    'role' => $m->sender_type === 'customer' ? 'customer' : 'agent',
                    'content' => $m->content,
                ])
                ->values()
                ->toArray();
        } elseif ($commentId) {
            $comment = Comment::with('conversation.customer', 'conversation.channel')->findOrFail($commentId);
            $conversationId = $comment->conversation_id;
            $prompt = $comment->content;
        }

        if (empty($prompt)) {
            return response()->json([
                'response' => 'Müşteri mesajı bulunamadı. Lütfen manuel yanıt yazın.',
                'matched_source' => null,
                'training_data_ids' => [],
            ]);
        }

        $claude = app(ClaudeService::class);
        $modelUsed = 'keyword_match';
        $matchedSource = null;
        $trainingDataIds = [];

        // Determine channel type for source-aware RAG
        $channelType = null;
        if ($conversationId) {
            $channelType = ($conversation->channel->type ?? null);
        } elseif ($commentId) {
            $channelType = ($comment->conversation->channel->type ?? null);
        }

        $tenantId = $request->user()->tenant_id;
        // For super admins (tenant_id is null), check X-Tenant-Id header
        if (! $tenantId && $request->hasHeader('X-Tenant-Id')) {
            $tenantId = (int) $request->header('X-Tenant-Id');
        }

        // Load tenant system prompt override
        $systemPromptOverride = null;
        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            $systemPromptOverride = $tenant?->settings['ai_system_prompt'] ?? null;
        }

        // Bakiye kapısı: gelen kutusu AI kapalıysa API'ye gitme; aşağıdaki
        // ücretsiz eğitim-verisi eşleştirmesi devreye girsin.
        if ($claude->isAvailable() && ClaudeKeyPolicy::allows($tenantId, 'inbox')) {
            // Use Claude API with source-aware RAG, tenant-scoped
            $result = $claude->generateResponse($prompt, $conversationHistory, [], $channelType, $tenantId, $systemPromptOverride, $conversationId, $commentId);
            $response = $result['content'];
            $confidence = $result['confidence'];
            $detectedIntent = $result['intent'];
            $extractedEntities = $result['entities'];
            $modelUsed = config('services.claude.model', 'claude-sonnet');
            $matchedSource = 'claude';
        } else {
            // Fallback: keyword matching
            $response = null;
            $confidence = 0.6;
            $detectedIntent = [];
            $extractedEntities = [];

            $words = array_filter(
                explode(' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $prompt))),
                fn ($w) => mb_strlen($w) > 2
            );

            if (count($words) > 0) {
                $trainingMatch = AiTrainingData::where('is_active', true)
                    ->where(function ($q) use ($words) {
                        foreach ($words as $word) {
                            $q->orWhere('question', 'ilike', "%{$word}%");
                        }
                    })
                    ->orderByDesc('quality_score')
                    ->first();

                if ($trainingMatch) {
                    $response = $trainingMatch->answer;
                    $matchedSource = 'training_data';
                    $trainingDataIds = [$trainingMatch->id];
                    $trainingMatch->increment('used_count');
                }
            }

            if (! $response && count($words) > 0) {
                $kbMatch = KnowledgeBase::where('is_active', true)
                    ->where(function ($q) use ($words) {
                        foreach ($words as $word) {
                            $q->orWhere('title', 'ilike', "%{$word}%")
                              ->orWhere('content', 'ilike', "%{$word}%");
                        }
                    })
                    ->first();

                if ($kbMatch) {
                    $response = $kbMatch->content;
                    $matchedSource = 'knowledge_base';
                }
            }

            if (! $response) {
                $response = 'Merhaba, mesajınız için teşekkür ederiz. Konuyla ilgili en kısa sürede size yardımcı olacağız. Lütfen beklemede kalın.';
                $matchedSource = 'template';
            }
        }

        $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        // Log the AI response
        AiResponseLog::create([
            'tenant_id' => $request->user()->tenant_id,
            'conversation_id' => $conversationId,
            'comment_id' => $commentId,
            'user_id' => $request->user()->id,
            'prompt' => $prompt,
            'ai_response' => $response,
            'response_time_ms' => $responseTimeMs,
            'model_used' => $modelUsed,
            'training_data_ids' => $trainingDataIds,
            'confidence_score' => $confidence ?? null,
            'detected_intent' => $detectedIntent ?? null,
            'extracted_entities' => $extractedEntities ?? null,
        ]);

        return response()->json([
            'response' => $response,
            'confidence' => $confidence ?? null,
            'matched_source' => $matchedSource,
            'training_data_ids' => $trainingDataIds,
        ]);
    }

    // ── AI Düzenleme Geçmişi ──

    public function editHistory(Request $request): JsonResponse
    {
        $query = Message::where('is_ai_generated', true)
            ->where('sender_type', 'agent')
            ->whereNotNull('ai_original_content')
            ->with([
                'conversation:id,channel_id,customer_id,platform_conversation_id',
                'conversation.channel:id,name,type',
                'conversation.customer:id,name',
                'sender:id,name',
            ]);

        // Filter: all, edited, approved
        $filter = $request->input('filter', 'all');
        if ($filter === 'edited') {
            $query->whereColumn('content', '!=', 'ai_original_content');
        } elseif ($filter === 'approved') {
            $query->whereColumn('content', 'ai_original_content');
        }

        if ($request->filled('channel_type')) {
            $query->whereHas('conversation.channel', fn ($q) => $q->where('type', $request->channel_type));
        }

        if ($request->filled('user_id')) {
            $query->where('sender_id', $request->user_id);
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('content', 'ilike', "%{$search}%")
                  ->orWhere('ai_original_content', 'ilike', "%{$search}%");
            });
        }

        $messages = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        // Attach the customer question (last customer message before this agent message)
        $messages->getCollection()->transform(function ($msg) {
            $customerMsg = Message::where('conversation_id', $msg->conversation_id)
                ->where('sender_type', 'customer')
                ->where('created_at', '<', $msg->created_at)
                ->orderByDesc('created_at')
                ->first(['content']);
            $msg->customer_question = $customerMsg?->content;

            return $msg;
        });

        return response()->json($messages);
    }

    public function editHistoryStats(): JsonResponse
    {
        $base = Message::where('is_ai_generated', true)
            ->where('sender_type', 'agent')
            ->whereNotNull('ai_original_content');

        $total = (clone $base)->count();
        $edited = (clone $base)->whereColumn('content', '!=', 'ai_original_content')->count();
        $approved = $total - $edited;

        return response()->json([
            'total' => $total,
            'edited' => $edited,
            'approved' => $approved,
            'edit_rate' => $total > 0 ? (float) number_format(($edited / $total) * 100, 1, '.', '') : 0,
        ]);
    }

    // ── AI Eğitim Verileri ──

    public function trainingData(Request $request): JsonResponse
    {
        $query = AiTrainingData::query();

        if ($request->filled('channel_type')) {
            $query->where('channel_type', $request->channel_type);
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->source_type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('question', 'ilike', "%{$search}%")
                  ->orWhere('answer', 'ilike', "%{$search}%");
            });
        }

        $data = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        return response()->json($data);
    }

    public function trainingDataCounts(Request $request): JsonResponse
    {
        $base = AiTrainingData::query();

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $base->where(function ($q) use ($search) {
                $q->where('question', 'ilike', "%{$search}%")
                  ->orWhere('answer', 'ilike', "%{$search}%");
            });
        }

        $counts = $base->selectRaw("
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE is_active = true) AS active,
            COUNT(*) FILTER (WHERE is_active = false) AS inactive,
            COUNT(*) FILTER (WHERE source_type = 'trendyol') AS trendyol,
            COUNT(*) FILTER (WHERE source_type = 'hepsiburada') AS hepsiburada,
            COUNT(*) FILTER (WHERE source_type = 'ticimax') AS ticimax,
            COUNT(*) FILTER (WHERE source_type = 'conversation') AS conversation,
            COUNT(*) FILTER (WHERE source_type = 'manual') AS manual
        ")->first();

        return response()->json($counts);
    }

    public function storeTrainingData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string',
            'answer' => 'required|string',
            'channel_type' => 'sometimes|nullable|string',
            'context' => 'sometimes|nullable|array',
        ]);

        $data = AiTrainingData::create([
            ...$validated,
            'source_type' => 'manual',
            'is_active' => true,
            'used_count' => 0,
        ]);

        return response()->json($data, 201);
    }

    public function updateTrainingData(Request $request, AiTrainingData $trainingData): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'sometimes|string',
            'answer' => 'sometimes|string',
            'channel_type' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $trainingData->update($validated);

        return response()->json($trainingData);
    }

    public function destroyTrainingData(AiTrainingData $trainingData): JsonResponse
    {
        $trainingData->delete();

        return response()->json(['message' => 'Eğitim verisi silindi.']);
    }

    // ── AI Response Logs ──

    public function responseLogs(Request $request): JsonResponse
    {
        $query = AiResponseLog::with([
            'user:id,name',
            'conversation:id,channel_id',
        ]);

        if ($request->filled('was_approved')) {
            $query->where('was_approved', filter_var($request->was_approved, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('prompt', 'ilike', "%{$search}%")
                  ->orWhere('ai_response', 'ilike', "%{$search}%");
            });
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        return response()->json($logs);
    }

    public function logResponse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'sometimes|nullable|exists:conversations,id',
            'comment_id' => 'sometimes|nullable|exists:comments,id',
            'prompt' => 'required|string',
            'ai_response' => 'required|string',
            'final_response' => 'sometimes|nullable|string',
            'was_approved' => 'sometimes|boolean',
            'was_edited' => 'sometimes|boolean',
            'response_time_ms' => 'sometimes|nullable|integer',
            'model_used' => 'sometimes|nullable|string',
            'training_data_ids' => 'sometimes|nullable|array',
        ]);

        $log = AiResponseLog::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($log, 201);
    }
}
