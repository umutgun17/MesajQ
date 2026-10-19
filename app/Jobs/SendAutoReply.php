<?php

namespace App\Jobs;

use App\Models\AiResponseLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\ClaudeKeyPolicy;
use App\Services\ClaudeService;
use App\Services\HepsiburadaService;
use App\Services\MetaService;
use App\Services\TicimaxService;
use App\Services\TrendyolService;
use App\Services\EmailService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $conversationId,
        public int $tenantId,
    ) {}

    public function handle(ClaudeService $claudeService, MetaService $metaService, WhatsAppService $whatsAppService): void
    {
        // Bakiye kapısı: kiracının Claude anahtarı varsayılan olarak yalnız
        // chatbot içindir; otomatik yanıt panelden açılmadıkça çalışmaz.
        if (! ClaudeKeyPolicy::allows($this->tenantId, 'inbox')) {
            Log::info('SendAutoReply: gelen kutusu AI kapalı, atlandı', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Load conversation with relations (bypass tenant scope)
        $conversation = Conversation::withoutGlobalScopes()
            ->with(['messages', 'customer', 'channel'])
            ->find($this->conversationId);

        if (! $conversation) {
            Log::warning('SendAutoReply: Conversation not found', ['id' => $this->conversationId]);
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
            Log::info('SendAutoReply: Agent already replied, skipping', [
                'conversation_id' => $this->conversationId,
            ]);
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

        // Load tenant settings and system prompt override
        $tenant = $this->tenantId ? \App\Models\Tenant::find($this->tenantId) : null;
        $systemPromptOverride = $tenant?->settings['ai_system_prompt'] ?? null;

        // Generate response (source-aware, tenant-scoped)
        $channelType = $conversation->channel->type ?? null;
        $result = $claudeService->generateResponse($prompt, $history, [], $channelType, $this->tenantId, $systemPromptOverride, $this->conversationId);
        $aiResponse = $result['content'];
        $confidence = $result['confidence'];
        $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);
        $modelUsed = $claudeService->isAvailable()
            ? config('services.claude.model', 'claude-sonnet')
            : 'keyword_match';

        // Confidence threshold — skip sending if below minimum
        $settings = $tenant?->settings ?? [];
        $minConfidence = (float) ($settings['auto_reply_min_confidence'] ?? 0.7);

        if ($confidence < $minConfidence) {
            Log::info('SendAutoReply: Low confidence, skipping auto-reply', [
                'conversation_id' => $this->conversationId,
                'confidence' => $confidence,
                'threshold' => $minConfidence,
                'model' => $modelUsed,
            ]);

            // Log for review but don't send
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

            return;
        }

        // Determine channel type and send message
        $channel = $conversation->channel;
        if (! $channel) {
            Log::warning('SendAutoReply: No channel found', ['conversation_id' => $this->conversationId]);
            return;
        }

        $recipientId = $conversation->customer?->platform_id;
        if (! $recipientId) {
            Log::error('SendAutoReply: No recipient platform_id', ['conversation_id' => $this->conversationId]);
            return;
        }

        $credentials = $channel->credentials ?? [];
        $channelType = $channel->type;

        // Route message to the correct platform
        if ($channelType === 'whatsapp') {
            $settings      = $channel->settings ?? [];
            $accessToken   = $whatsAppService->getAccessToken($credentials);
            $phoneNumberId = $whatsAppService->getPhoneNumberId($settings);

            if (! $accessToken || ! $phoneNumberId) {
                Log::error('SendAutoReply: No WhatsApp credentials', ['channel_id' => $channel->id]);
                return;
            }

            $result = $whatsAppService->sendMessage($recipientId, $aiResponse, $phoneNumberId, $accessToken);
        } elseif (in_array($channelType, ['instagram-message', 'facebook-message'])) {
            $pageAccessToken = $metaService->getPageAccessToken($credentials);

            if (! $pageAccessToken) {
                Log::error('SendAutoReply: No page access token', ['channel_id' => $channel->id]);
                return;
            }

            $result = $metaService->sendMessage($recipientId, $aiResponse, $pageAccessToken);
        } elseif ($channelType === 'ticimax-ticket') {
            $metadata = $conversation->metadata ?? [];
            $ticketId = $metadata['ticimax_ticket_id'] ?? null;

            if (! $ticketId) {
                Log::error('SendAutoReply: No ticimax_ticket_id', ['conversation_id' => $this->conversationId]);
                return;
            }

            $ticimaxService = TicimaxService::fromChannel($channel);
            if (! $ticimaxService->isAvailable()) {
                Log::error('SendAutoReply: Ticimax credentials missing', ['channel_id' => $channel->id]);
                return;
            }

            $result = $ticimaxService->answerTicket((int) $ticketId, $aiResponse);
        } elseif ($channelType === 'trendyol-product') {
            $metadata = $conversation->metadata ?? [];
            $questionId = $metadata['trendyol_question_id'] ?? null;

            if (! $questionId) {
                Log::error('SendAutoReply: No trendyol_question_id', ['conversation_id' => $this->conversationId]);
                return;
            }

            $trendyolService = TrendyolService::fromChannel($channel);
            if (! $trendyolService->isAvailable()) {
                Log::error('SendAutoReply: Trendyol credentials missing', ['channel_id' => $channel->id]);
                return;
            }

            $result = $trendyolService->answerQuestion((int) $questionId, $aiResponse);
        } elseif ($channelType === 'hepsiburada-product') {
            $metadata = $conversation->metadata ?? [];
            $issueNumber = $metadata['hepsiburada_question_id'] ?? null;

            if (! $issueNumber) {
                Log::error('SendAutoReply: No hepsiburada_question_id', ['conversation_id' => $this->conversationId]);
                return;
            }

            $hbService = HepsiburadaService::fromChannel($channel);
            if (! $hbService->isAvailable()) {
                Log::error('SendAutoReply: Hepsiburada credentials missing', ['channel_id' => $channel->id]);
                return;
            }

            $result = $hbService->answerQuestion($issueNumber, $aiResponse);
        } elseif ($channelType === 'email') {
            $emailService = new EmailService($channel);

            $lastEmail = \App\Models\EmailMessage::withoutGlobalScopes()
                ->where('conversation_id', $this->conversationId)
                ->where('ai_category', 'customer_request')
                ->orderByDesc('created_at')
                ->first();

            if (! $lastEmail) {
                Log::error('SendAutoReply: No email found for conversation', ['conversation_id' => $this->conversationId]);
                return;
            }

            // sendReply() bir dizi döndürür: ['success'=>bool, 'error'=>?string, 'message_id'=>?string].
            // Eskiden tüm dizi $success'e atanıp boolean gibi kullanılıyordu → dizi her zaman truthy,
            // başarısız otomatik cevaplar "gönderildi" sayılıyordu. success alanını açıkça oku.
            $sendResult = $emailService->sendReply(
                $lastEmail->from_email,
                $lastEmail->subject ?? '(Konu yok)',
                $aiResponse,
                $lastEmail->message_id,
                $lastEmail->references
            );

            $sentOk = $sendResult['success'] ?? false;
            $result = [
                'success' => $sentOk,
                'message_id' => $sentOk ? 'email_auto_reply_' . $lastEmail->id : null,
                'error' => $sendResult['error'] ?? null,
            ];

            // Firma SMTP'si yerine yerel sendmail'e düşüldüyse teslim şüpheli —
            // otomatik cevapta kimse ekrana bakmadığı için EN AZINDAN logla.
            // Bkz EmailService::sendReply ve 4 Eyl 2026 teslimat olayı.
            if (! empty($sendResult['fallback'])) {
                Log::error('SendAutoReply: e-posta yerel sendmail ile gönderildi, teslim RİSKLİ (SPF/DKIM yok)', [
                    'conversation_id' => $this->conversationId,
                    'reason' => $sendResult['fallback_reason'] ?? null,
                ]);
            }
        } else {
            Log::info('SendAutoReply: Channel type not supported for auto-reply', [
                'channel_type' => $channelType,
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        if (! $result['success']) {
            Log::error('SendAutoReply: Failed to send message', [
                'conversation_id' => $this->conversationId,
                'error' => $result['error'] ?? null,
            ]);
            return;
        }

        // Create message record
        Message::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversation->id,
            'sender_type' => 'agent',
            'sender_id' => null,
            'content' => $aiResponse,
            'is_ai_generated' => true,
            'ai_original_content' => $aiResponse,
            'platform_message_id' => $result['message_id'] ?? null,
            'metadata' => ['auto_reply' => true],
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => now(),
            'status' => 'resolved',
        ]);

        // Log the AI response
        AiResponseLog::create([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'prompt' => $prompt,
            'ai_response' => $aiResponse,
            'was_approved' => true,
            'confidence_score' => $confidence,
            'response_time_ms' => $responseTimeMs,
            'model_used' => $modelUsed,
        ]);

        Log::info('SendAutoReply: Auto-reply sent', [
            'conversation_id' => $this->conversationId,
            'model' => $modelUsed,
        ]);
    }
}
