<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiSuggestion;
use App\Jobs\SendAutoReply;
use App\Models\AiTrainingData;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\TrendyolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTrendyolQuestions extends Command
{
    protected $signature = 'trendyol:sync-questions
                            {--status= : Question status filter (empty=all)}
                            {--limit=50 : Number of questions per page}
                            {--pages=0 : Number of pages (0=all pages)}
                            {--resync : Re-check unanswered conversations for Trendyol-side answers}';

    protected $description = 'Trendyol ürün sorularını senkronize eder (çift yönlü)';

    protected int $totalSynced = 0;
    protected int $totalUpdated = 0;
    protected int $totalSkipped = 0;
    protected int $totalTraining = 0;

    public function handle(): int
    {
        $channels = Channel::withoutGlobalScopes()
            ->where('type', 'trendyol-product')
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif trendyol-product kanalı bulunamadı.');
            return 1;
        }

        foreach ($channels as $channel) {
            $service = TrendyolService::fromChannel($channel);

            if (! $service->isAvailable()) {
                $this->warn("Kanal #{$channel->id} (Tenant #{$channel->tenant_id}): Credential eksik, atlanıyor.");
                continue;
            }

            $this->info("Kanal #{$channel->id} (Tenant #{$channel->tenant_id}) işleniyor...");

            $tenantId = $channel->tenant_id;

            if ($this->option('resync')) {
                $this->resyncAnswered($service, $channel, $tenantId);
            }

            $this->pullQuestions($service, $channel, $tenantId);

            $channel->update(['last_synced_at' => now()]);
        }

        $this->info("Tamamlandı: {$this->totalSynced} yeni, {$this->totalUpdated} güncellendi, {$this->totalSkipped} mevcut, {$this->totalTraining} eğitim verisi");
        Log::info('SyncTrendyolQuestions: completed', [
            'synced' => $this->totalSynced,
            'updated' => $this->totalUpdated,
            'skipped' => $this->totalSkipped,
            'training' => $this->totalTraining,
        ]);

        return 0;
    }

    /**
     * Pull questions from Trendyol API and sync to local DB
     */
    protected function pullQuestions(TrendyolService $trendyolService, Channel $channel, int $tenantId): void
    {
        $status = $this->option('status') ?: null;
        $limit = (int) $this->option('limit');
        $maxPages = (int) $this->option('pages');

        $page = 0;
        while (true) {
            $params = [
                'page' => $page,
                'size' => $limit,
                'orderByField' => 'CreatedDate',
                'orderByDirection' => 'DESC',
            ];

            if ($status) {
                $params['status'] = $status;
            }

            $result = $trendyolService->getQuestions($params);
            $questions = $result['content'] ?? [];

            if (empty($questions)) {
                break;
            }

            foreach ($questions as $question) {
                $this->syncQuestion($question, $channel, $tenantId);
            }

            $this->info("Sayfa {$page}: " . count($questions) . " soru işlendi");

            $page++;

            // Stop if we've reached max pages (0 = unlimited)
            if ($maxPages > 0 && $page >= $maxPages) {
                break;
            }

            // Stop if we've reached the last page
            $totalPages = $result['totalPages'] ?? 1;
            if ($page >= $totalPages) {
                break;
            }

            // Rate limit: small pause every 10 pages to avoid hitting API limits
            if ($page % 10 === 0) {
                usleep(500000); // 0.5s
            }
        }
    }

    /**
     * Re-sync: check our unanswered conversations against Trendyol for new answers
     */
    protected function resyncAnswered(TrendyolService $trendyolService, Channel $channel, int $tenantId): void
    {
        $this->info('Resync: Cevap bekleyen konuşmalar kontrol ediliyor...');

        // Get all open/pending trendyol conversations (unanswered on our side)
        $unanswered = Conversation::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->whereIn('status', ['open', 'pending'])
            ->get();

        if ($unanswered->isEmpty()) {
            $this->info('Resync: Cevap bekleyen konuşma yok.');
            return;
        }

        $this->info("Resync: {$unanswered->count()} konuşma kontrol ediliyor...");

        foreach ($unanswered as $conversation) {
            $metadata = $conversation->metadata ?? [];
            $questionId = $metadata['trendyol_question_id'] ?? null;

            if (! $questionId) {
                continue;
            }

            // Fetch question detail from Trendyol
            $question = $trendyolService->getQuestion((int) $questionId);

            if (! $question) {
                // API returned null — could be a transient network error or deleted question.
                // Do NOT close the conversation; closing on a network blip causes data loss.
                $this->warn("Resync: Soru #{$questionId} alınamadı (API hatası veya geçici hata), atlanıyor.");
                continue;
            }

            // If answered on Trendyol, sync the answer
            if (($question['status'] ?? '') === 'ANSWERED' && isset($question['answer']['text']) && ! empty($question['answer']['text'])) {
                $answer = $question['answer'];
                $answerMsgId = "ta_{$answer['id']}";

                // Check both sync format (ta_{answerId}) and panel format (ta_answered_{questionId})
                $answerExists = Message::where('conversation_id', $conversation->id)
                    ->where(function ($q) use ($answerMsgId, $questionId) {
                        $q->where('platform_message_id', $answerMsgId)
                          ->orWhere('platform_message_id', "ta_answered_{$questionId}");
                    })
                    ->exists();

                if (! $answerExists) {
                    Message::create([
                        'tenant_id' => $tenantId,
                        'conversation_id' => $conversation->id,
                        'sender_type' => 'agent',
                        'sender_id' => null,
                        'content' => $answer['text'],
                        'platform_message_id' => $answerMsgId,
                        'metadata' => [
                            'answered_on' => 'trendyol',
                            'trendyol_question_id' => (int) $questionId,
                        ],
                        'created_at' => $this->parseTimestamp($answer['creationDate']),
                    ]);

                    $conversation->update([
                        'status' => 'resolved',
                        'is_read' => true,
                        'unread_count' => 0,
                    ]);

                    // Also save as training data
                    $this->saveTrainingData($conversation, $question);

                    $this->totalUpdated++;
                    $this->line("  ✓ Soru #{$questionId} Trendyol'dan cevaplandı");
                }
            }

            // Rate limit: small delay between individual question API calls
            usleep(100000); // 0.1s
        }
    }

    protected function syncQuestion(array $question, Channel $channel, int $tenantId): void
    {
        $questionId = (string) $question['id'];
        $customerId = (string) ($question['customerId'] ?? 0);

        // Find or create customer
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform_id' => $customerId,
                'platform_type' => 'trendyol',
            ],
            [
                'name' => $question['userName'] ?: 'Trendyol Müşteri',
            ]
        );

        // Conversation per question
        $isNew = false;
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel_id', $channel->id)
            ->where('platform_conversation_id', "q_{$questionId}")
            ->first();

        if (! $conversation) {
            $isNew = true;
            $conversation = Conversation::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'platform_conversation_id' => "q_{$questionId}",
                'customer_id' => $customer->id,
                'type' => 'message',
                'status' => $question['status'] === 'ANSWERED' ? 'resolved' : 'open',
                'last_message_at' => $this->parseTimestamp($question['creationDate']),
                'metadata' => [
                    'trendyol_question_id' => (int) $questionId,
                    'product_name' => $question['productName'] ?? null,
                    'product_main_id' => $question['productMainId'] ?? null,
                    'image_url' => $question['imageUrl'] ?? null,
                    'web_url' => $question['webUrl'] ?? null,
                ],
            ]);
        } elseif (($question['status'] ?? '') === 'WAITING_FOR_ANSWER' && in_array($conversation->status, ['closed', 'resolved'])) {
            // Trendyol says this question is still unanswered but our DB has it as closed/resolved.
            // This happens after network errors in resync (false-close) or failed answer submissions.
            // Re-open it so agents can see and answer it.
            $conversation->update([
                'status' => 'open',
                'is_read' => false,
                'unread_count' => 1,
            ]);
            $this->totalUpdated++;
            $this->line("  ↺ Soru #{$questionId} yeniden açıldı (Trendyol'da hâlâ cevaplanmamış)");
        }

        // Create customer question message if not exists
        $questionMsgExists = Message::where('conversation_id', $conversation->id)
            ->where('platform_message_id', "tq_{$questionId}")
            ->exists();

        if (! $questionMsgExists) {
            $content = $question['text'];
            if (! empty($question['productName'])) {
                $content = "[{$question['productName']}]\n\n{$question['text']}";
            }

            Message::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'content' => $content,
                'platform_message_id' => "tq_{$questionId}",
                'metadata' => [
                    'trendyol_question_id' => (int) $questionId,
                    'product_name' => $question['productName'] ?? null,
                    'product_main_id' => $question['productMainId'] ?? null,
                ],
                'created_at' => $this->parseTimestamp($question['creationDate']),
            ]);
        }

        // Sync answer if exists
        $answerSynced = false;
        if (isset($question['answer']) && ! empty($question['answer']['text'])) {
            $answer = $question['answer'];
            $answerMsgId = "ta_{$answer['id']}";

            // Check both sync format (ta_{answerId}) and panel format (ta_answered_{questionId})
            $answerExists = Message::where('conversation_id', $conversation->id)
                ->where(function ($q) use ($answerMsgId, $questionId) {
                    $q->where('platform_message_id', $answerMsgId)
                      ->orWhere('platform_message_id', "ta_answered_{$questionId}");
                })
                ->exists();

            if (! $answerExists) {
                Message::create([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'agent',
                    'sender_id' => null,
                    'content' => $answer['text'],
                    'platform_message_id' => $answerMsgId,
                    'metadata' => [
                        'answered_on' => 'trendyol',
                        'trendyol_question_id' => (int) $questionId,
                    ],
                    'created_at' => $this->parseTimestamp($answer['creationDate']),
                ]);

                $conversation->update([
                    'status' => 'resolved',
                    'is_read' => true,
                    'unread_count' => 0,
                ]);

                $answerSynced = true;

                // If this was a previously open conversation that got answered on Trendyol
                if (! $isNew) {
                    $this->totalUpdated++;
                }
            }
        } elseif ($isNew) {
            $conversation->update([
                'is_read' => false,
                'unread_count' => 1,
            ]);

            // Dispatch auto-reply for new unanswered questions
            $this->dispatchAutoReply($conversation, $tenantId);

            // Pre-generate AI suggestion
            GenerateAiSuggestion::dispatch($conversation->id, $tenantId)
                ->delay(now()->addSeconds(5));
        }

        // Save training data for answered questions
        if ($answerSynced || ($isNew && isset($question['answer']['text']) && ! empty($question['answer']['text']))) {
            $this->saveTrainingData($conversation, $question);
        }

        if ($isNew) {
            $this->totalSynced++;
        } elseif ($answerSynced) {
            // Already counted in totalUpdated above
        } else {
            $this->totalSkipped++;
        }
    }

    /**
     * Save Q&A pair as AI training data
     */
    protected function saveTrainingData(Conversation $conversation, array $question): void
    {
        $questionText = $question['text'] ?? '';
        $answerText = $question['answer']['text'] ?? '';

        if (mb_strlen($questionText) < 5 || mb_strlen($answerText) < 3) {
            return;
        }

        $exists = AiTrainingData::withoutGlobalScopes()
            ->where('source_type', 'trendyol')
            ->where('source_id', $conversation->id)
            ->exists();

        if ($exists) {
            return;
        }

        $productName = $question['productName'] ?? null;
        if ($productName) {
            $questionText = "[{$productName}]\n\n{$questionText}";
        }

        AiTrainingData::create([
            'tenant_id' => $conversation->tenant_id,
            'source_type' => 'trendyol',
            'source_id' => $conversation->id,
            'channel_type' => 'trendyol-product',
            'question' => $questionText,
            'answer' => $answerText,
            'context' => [
                'conversation_id' => $conversation->id,
                'product_name' => $productName,
                'product_main_id' => $question['productMainId'] ?? null,
                'trendyol_question_id' => $question['id'] ?? null,
                'source_label' => 'Trendyol Ürün Sorusu',
            ],
            'quality_score' => 0.75,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $this->totalTraining++;
    }

    /**
     * Dispatch auto-reply job if tenant has auto_reply enabled.
     */
    protected function dispatchAutoReply(Conversation $conversation, int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);
        $settings = $tenant?->settings ?? [];

        if (($settings['auto_reply'] ?? 'disabled') !== 'auto') {
            return;
        }

        $delayMin = max(1, (int) ($settings['auto_reply_delay_min'] ?? 1));
        $delayMax = max($delayMin, (int) ($settings['auto_reply_delay_max'] ?? 3));
        $delayMinutes = rand($delayMin, $delayMax);

        SendAutoReply::dispatch($conversation->id, $tenantId)
            ->delay(now()->addMinutes($delayMinutes));
    }

    protected function parseTimestamp(int|null $timestamp): \Carbon\Carbon
    {
        if (! $timestamp) {
            return now();
        }

        return \Carbon\Carbon::createFromTimestampMs($timestamp);
    }
}
