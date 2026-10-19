<?php

namespace App\Jobs;

use App\Models\AiResponseLog;
use App\Models\Conversation;
use App\Services\Ai\ClaudeKeyPolicy;
use App\Services\ClaudeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAiSuggestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $conversationId,
        public int $tenantId,
    ) {}

    public function handle(ClaudeService $claudeService): void
    {
        // Bakiye kapısı: kiracının Claude anahtarı varsayılan olarak yalnız
        // chatbot içindir; gelen kutusu önerisi panelden açılmadıkça üretilmez.
        if (! ClaudeKeyPolicy::allows($this->tenantId, 'inbox')) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->with(['messages', 'customer', 'channel'])
            ->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        // Already has a suggestion — skip
        if ($conversation->ai_suggestion) {
            return;
        }

        // Check if an agent already replied after the last customer message
        $lastCustomerMessage = $conversation->messages
            ->where('sender_type', 'customer')
            ->sortByDesc('id')
            ->first();

        if (! $lastCustomerMessage) {
            return;
        }

        $hasAgentReply = $conversation->messages
            ->where('sender_type', 'agent')
            ->where('created_at', '>', $lastCustomerMessage->created_at)
            ->isNotEmpty();

        if ($hasAgentReply) {
            return;
        }

        // Get the last non-empty customer message as prompt
        $promptMessage = $conversation->messages
            ->where('sender_type', 'customer')
            ->filter(fn ($m) => trim($m->content ?? '') !== '')
            ->sortByDesc('id')
            ->first();

        $prompt = $promptMessage?->content ?? '';
        if (empty($prompt)) {
            return;
        }

        // Build conversation history
        $history = $conversation->messages
            ->sortBy('created_at')
            ->map(fn ($m) => [
                'role' => $m->sender_type === 'customer' ? 'customer' : 'agent',
                'content' => $m->content,
            ])
            ->values()
            ->toArray();

        $startTime = microtime(true);

        $tenant = $this->tenantId ? \App\Models\Tenant::find($this->tenantId) : null;
        $systemPromptOverride = $tenant?->settings['ai_system_prompt'] ?? null;

        $channelType = $conversation->channel->type ?? null;
        $result = $claudeService->generateResponse($prompt, $history, [], $channelType, $this->tenantId, $systemPromptOverride, $this->conversationId);
        $aiResponse = $result['content'];
        $confidence = $result['confidence'];
        $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        // API başarısız olduysa (kredi bitti, hata vs.) suggestion üretme
        if (empty($aiResponse)) {
            Log::warning('GenerateAiSuggestion: API returned empty, skipping', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        $modelUsed = config('services.claude.model', 'claude-sonnet');

        // Save suggestion to conversation
        $conversation->update([
            'ai_suggestion' => $aiResponse,
            'ai_suggestion_confidence' => $confidence,
        ]);

        // Log for analytics
        AiResponseLog::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'prompt' => $prompt,
            'ai_response' => $aiResponse,
            'was_approved' => false,
            'confidence_score' => $confidence,
            'response_time_ms' => $responseTimeMs,
            'model_used' => $modelUsed,
        ]);

        Log::info('GenerateAiSuggestion: Suggestion saved', [
            'conversation_id' => $this->conversationId,
            'confidence' => $confidence,
            'model' => $modelUsed,
        ]);
    }
}
