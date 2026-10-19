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
use App\Services\HepsiburadaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncHepsiburadaQuestions extends Command
{
    protected $signature = 'hepsiburada:sync-questions
                            {--status= : Question status filter (1=waiting, 2=answered, 3=rejected, 4=auto-closed)}
                            {--limit=50 : Number of questions per page}
                            {--pages=0 : Number of pages (0=all pages)}
                            {--resync : Re-check unanswered conversations for Hepsiburada-side answers}';

    protected $description = 'Hepsiburada ürün sorularını senkronize eder (çift yönlü)';

    protected int $totalSynced = 0;
    protected int $totalUpdated = 0;
    protected int $totalSkipped = 0;
    protected int $totalTraining = 0;

    public function handle(): int
    {
        $channels = Channel::withoutGlobalScopes()
            ->where('type', 'hepsiburada-product')
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif hepsiburada-product kanalı bulunamadı.');
            return 1;
        }

        foreach ($channels as $channel) {
            $service = HepsiburadaService::fromChannel($channel);

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
        Log::info('SyncHepsiburadaQuestions: completed', [
            'synced' => $this->totalSynced,
            'updated' => $this->totalUpdated,
            'skipped' => $this->totalSkipped,
            'training' => $this->totalTraining,
        ]);

        return 0;
    }

    /**
     * Pull questions from Hepsiburada API and sync to local DB
     */
    protected function pullQuestions(HepsiburadaService $hepsiburadaService, Channel $channel, int $tenantId): void
    {
        $status = $this->option('status') ?: null;
        $limit = (int) $this->option('limit');
        $maxPages = (int) $this->option('pages');

        $page = 1; // Hepsiburada is 1-indexed
        while (true) {
            $params = [
                'page' => $page,
                'size' => $limit,
                'sortBy' => 0, // 0=creation date, 1=last update
            ];

            if ($status) {
                $params['Status'] = $status;
            }

            $result = $hepsiburadaService->getQuestions($params);
            $questions = $result['data'] ?? [];

            if (empty($questions)) {
                break;
            }

            foreach ($questions as $question) {
                $this->syncQuestion($question, $channel, $tenantId);
            }

            $this->info("Sayfa {$page}: " . count($questions) . " soru işlendi");

            $page++;

            // Stop if we've reached max pages (0 = unlimited)
            if ($maxPages > 0 && ($page - 1) >= $maxPages) {
                break;
            }

            // Stop if we've reached the last page
            $totalPages = $result['totalPageCount'] ?? $result['totalPages'] ?? 1;
            if ($page > $totalPages) {
                break;
            }

            // Rate limit: small pause every 10 pages (240 req/min limit)
            if (($page - 1) % 10 === 0) {
                usleep(500000); // 0.5s
            }
        }
    }

    /**
     * Re-sync: check our unanswered conversations against Hepsiburada for new answers
     */
    protected function resyncAnswered(HepsiburadaService $hepsiburadaService, Channel $channel, int $tenantId): void
    {
        $this->info('Resync: Cevap bekleyen konuşmalar kontrol ediliyor...');

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
            $issueNumber = $metadata['hepsiburada_question_id'] ?? null;

            if (! $issueNumber) {
                continue;
            }

            // Fetch question detail from Hepsiburada
            $question = $hepsiburadaService->getQuestion($issueNumber);

            if (! $question) {
                // API returned null — could be a transient network error or deleted question.
                // Do NOT close the conversation; closing on a network blip causes data loss.
                $this->warn("Resync: Soru #{$issueNumber} alınamadı (API hatası veya geçici hata), atlanıyor.");
                continue;
            }

            // Check if answered — status string or int
            $isAnswered = $this->isAnswered($question);
            $answerText = $this->extractAnswer($question);

            if ($isAnswered && ! empty($answerText)) {
                $answerMsgId = "hba_{$issueNumber}";

                $answerExists = Message::where('conversation_id', $conversation->id)
                    ->where('platform_message_id', $answerMsgId)
                    ->exists();

                if (! $answerExists) {
                    $answerDate = $this->extractAnswerDate($question);

                    Message::create([
                        'tenant_id' => $tenantId,
                        'conversation_id' => $conversation->id,
                        'sender_type' => 'agent',
                        'sender_id' => null,
                        'content' => $answerText,
                        'platform_message_id' => $answerMsgId,
                        'metadata' => [
                            'answered_on' => 'hepsiburada',
                            'hepsiburada_question_id' => $issueNumber,
                        ],
                        'created_at' => $answerDate,
                    ]);

                    $conversation->update([
                        'status' => 'resolved',
                        'is_read' => true,
                        'unread_count' => 0,
                    ]);

                    $this->saveTrainingData($conversation, $question);

                    $this->totalUpdated++;
                    $this->line("  ✓ Soru #{$issueNumber} Hepsiburada'dan cevaplandı");
                }
            }

            // Rate limit: small delay between individual question API calls
            usleep(100000); // 0.1s
        }
    }

    protected function syncQuestion(array $question, Channel $channel, int $tenantId): void
    {
        // HB API uses issueNumber as the unique identifier
        $issueNumber = (string) ($question['issueNumber'] ?? $question['number'] ?? $question['id'] ?? 0);
        $customerId = (string) ($question['customerId'] ?? 0);

        // Find or create customer
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform_id' => $customerId,
                'platform_type' => 'hepsiburada',
            ],
            [
                'name' => $question['customerName'] ?? 'Hepsiburada Müşteri',
            ]
        );

        // Conversation per question
        $isNew = false;
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel_id', $channel->id)
            ->where('platform_conversation_id', "hb_{$issueNumber}")
            ->first();

        $statusMap = $this->mapStatus($question['status'] ?? 'WaitingforAnswer');

        if (! $conversation) {
            $isNew = true;
            $conversation = Conversation::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'platform_conversation_id' => "hb_{$issueNumber}",
                'customer_id' => $customer->id,
                'type' => 'message',
                'status' => $statusMap,
                'last_message_at' => $this->parseTimestamp($question['createdAt'] ?? $question['createdDate'] ?? null),
                'metadata' => [
                    'hepsiburada_question_id' => $issueNumber,
                    'product_name' => $question['product']['name'] ?? $question['name'] ?? $question['productName'] ?? null,
                    'product_url' => $question['productUrl'] ?? null,
                    'product_sku' => $question['product']['sku'] ?? $question['sku'] ?? $question['stockCode'] ?? null,
                    'stock_code' => $question['product']['stockCode'] ?? null,
                    'image_url' => $question['product']['imageUrl'] ?? $question['imageUrl'] ?? null,
                    'subject' => $question['subject']['description'] ?? null,
                    'expire_date' => $question['expireDate'] ?? null,
                ],
            ]);
        }

        // Sync all messages from conversations array (supports follow-up messages)
        $newMessagesAdded = $this->syncConversationMessages($question, $conversation, $channel, $tenantId, $isNew);

        // Update conversation status based on HB status
        $statusMap = $this->mapStatus($question['status'] ?? 'WaitingforAnswer');

        if (! $isNew && $conversation->status !== $statusMap) {
            $updateData = ['status' => $statusMap];

            if ($statusMap === 'open') {
                // Always mark as unread when re-opening, even if no new messages.
                // Without this, re-opened conversations stay marked as read and agents miss them.
                $updateData['is_read'] = false;
                $updateData['unread_count'] = max(1, $newMessagesAdded);
                $updateData['last_message_at'] = now();

                GenerateAiSuggestion::dispatch($conversation->id, $tenantId)
                    ->delay(now()->addSeconds(5));

                $this->line("  ↺ Soru #{$issueNumber} yeniden açıldı (HB'de hâlâ cevaplanmamış)");
            }

            $conversation->update($updateData);
            $this->totalUpdated++;
        } elseif ($isNew && $statusMap === 'open') {
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
        $answerText = $this->extractAnswer($question);
        if (! empty($answerText) && ($isNew || $newMessagesAdded > 0)) {
            $this->saveTrainingData($conversation, $question);
        }

        if ($isNew) {
            $this->totalSynced++;
        } elseif ($newMessagesAdded > 0) {
            // Already counted in totalUpdated above if status changed
            if ($conversation->status === $statusMap) {
                $this->totalUpdated++;
            }
        } else {
            $this->totalSkipped++;
        }
    }

    /**
     * Sync all messages from HB conversations array.
     * Each message gets a unique platform_message_id: hbm_{issueNumber}_{index}
     * Returns the number of new messages added.
     */
    protected function syncConversationMessages(array $question, Conversation $conversation, Channel $channel, int $tenantId, bool $isNew): int
    {
        $issueNumber = (string) ($question['issueNumber'] ?? $question['number'] ?? $question['id'] ?? 0);
        $conversations = $question['conversations'] ?? [];
        $productName = $question['product']['name'] ?? $question['name'] ?? $question['productName'] ?? null;
        $newCount = 0;

        if (empty($conversations)) {
            // Fallback: use extractCustomerQuestion + extractAnswer (old behavior)
            return $this->syncQuestionLegacy($question, $conversation, $tenantId, $isNew);
        }

        foreach ($conversations as $index => $conv) {
            $from = $conv['from'] ?? '';
            $content = $conv['content'] ?? '';
            $createdAt = $this->parseTimestamp($conv['createdAt'] ?? null);

            if (empty($content)) {
                continue;
            }

            // Build unique message ID per conversation entry
            // First customer message uses hbq_, first merchant uses hba_ for backward compat
            if ($index === 0 && $from === 'Customer') {
                $platformMsgId = "hbq_{$issueNumber}";
            } elseif ($from === 'Merchant' && $this->isFirstMerchantMessage($conversations, $index)) {
                $platformMsgId = "hba_{$issueNumber}";
            } else {
                $platformMsgId = "hbm_{$issueNumber}_{$index}";
            }

            // Check by platform_message_id (exact match or legacy hba_answered_ format)
            $exists = Message::where('conversation_id', $conversation->id)
                ->where(function ($q) use ($platformMsgId, $issueNumber, $from) {
                    $q->where('platform_message_id', $platformMsgId);
                    // Also check legacy hba_answered_ format from panel-sent messages
                    if ($from === 'Merchant') {
                        $q->orWhere('platform_message_id', "hba_answered_{$issueNumber}");
                    }
                })
                ->exists();

            if ($exists) {
                // If legacy format exists, update it to current format for consistency
                if ($from === 'Merchant') {
                    Message::where('conversation_id', $conversation->id)
                        ->where('platform_message_id', "hba_answered_{$issueNumber}")
                        ->update(['platform_message_id' => $platformMsgId]);
                }
                continue;
            }

            // Also skip if same content already exists from panel (content-based dedup)
            if ($from === 'Merchant') {
                $contentExists = Message::where('conversation_id', $conversation->id)
                    ->where('sender_type', 'agent')
                    ->where('content', $content)
                    ->exists();

                if ($contentExists) {
                    continue;
                }
            }

            $senderType = $from === 'Merchant' ? 'agent' : 'customer';
            $messageContent = $content;

            // Prepend product name for first customer message
            if ($index === 0 && $from === 'Customer' && ! empty($productName)) {
                $messageContent = "[{$productName}]\n\n{$content}";
            }

            $metadata = [
                'hepsiburada_question_id' => $issueNumber,
            ];
            if ($senderType === 'customer') {
                $metadata['product_name'] = $productName;
                $metadata['product_sku'] = $question['product']['sku'] ?? $question['sku'] ?? $question['stockCode'] ?? null;
            } else {
                $metadata['answered_on'] = 'hepsiburada';
            }

            Message::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'sender_type' => $senderType,
                'sender_id' => null,
                'content' => $messageContent,
                'platform_message_id' => $platformMsgId,
                'metadata' => $metadata,
                'created_at' => $createdAt,
            ]);

            $newCount++;
        }

        return $newCount;
    }

    /**
     * Check if this is the first Merchant message in the conversations array.
     */
    protected function isFirstMerchantMessage(array $conversations, int $currentIndex): bool
    {
        for ($i = 0; $i < $currentIndex; $i++) {
            if (($conversations[$i]['from'] ?? '') === 'Merchant') {
                return false;
            }
        }
        return true;
    }

    /**
     * Legacy sync for questions without conversations array (backward compat).
     * Returns number of new messages added.
     */
    protected function syncQuestionLegacy(array $question, Conversation $conversation, int $tenantId, bool $isNew): int
    {
        $issueNumber = (string) ($question['issueNumber'] ?? $question['number'] ?? $question['id'] ?? 0);
        $newCount = 0;

        // Create customer question message if not exists
        $questionMsgExists = Message::where('conversation_id', $conversation->id)
            ->where('platform_message_id', "hbq_{$issueNumber}")
            ->exists();

        if (! $questionMsgExists) {
            $questionText = $this->extractCustomerQuestion($question);
            $content = $questionText;
            $productName = $question['product']['name'] ?? $question['name'] ?? $question['productName'] ?? null;
            if (! empty($productName) && ! empty($content)) {
                $content = "[{$productName}]\n\n{$content}";
            }

            Message::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'content' => $content ?: '(Soru metni bulunamadı)',
                'platform_message_id' => "hbq_{$issueNumber}",
                'metadata' => [
                    'hepsiburada_question_id' => $issueNumber,
                    'product_name' => $productName,
                    'product_sku' => $question['product']['sku'] ?? $question['sku'] ?? $question['stockCode'] ?? null,
                ],
                'created_at' => $this->parseTimestamp($question['createdAt'] ?? $question['createdDate'] ?? null),
            ]);
            $newCount++;
        }

        // Sync answer if exists
        $answerText = $this->extractAnswer($question);
        if (! empty($answerText)) {
            $answerMsgId = "hba_{$issueNumber}";
            $answerExists = Message::where('conversation_id', $conversation->id)
                ->where(function ($q) use ($answerMsgId, $issueNumber) {
                    $q->where('platform_message_id', $answerMsgId)
                      ->orWhere('platform_message_id', "hba_answered_{$issueNumber}");
                })
                ->exists();

            if (! $answerExists) {
                $answerDate = $this->extractAnswerDate($question);
                Message::create([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'agent',
                    'sender_id' => null,
                    'content' => $answerText,
                    'platform_message_id' => $answerMsgId,
                    'metadata' => [
                        'answered_on' => 'hepsiburada',
                        'hepsiburada_question_id' => $issueNumber,
                    ],
                    'created_at' => $answerDate,
                ]);

                $conversation->update([
                    'status' => 'resolved',
                    'is_read' => true,
                    'unread_count' => 0,
                ]);
                $newCount++;
            }
        }

        return $newCount;
    }

    /**
     * Extract the customer question text from the API response.
     * HB uses conversations[] array where from=Customer, or lastContent, or text field.
     */
    protected function extractCustomerQuestion(array $question): string
    {
        // Try conversations array first (most detailed)
        $conversations = $question['conversations'] ?? [];
        foreach ($conversations as $conv) {
            if (($conv['from'] ?? '') === 'Customer') {
                return $conv['content'] ?? '';
            }
        }

        // Fallback to direct fields
        return $question['lastContent'] ?? $question['text'] ?? $question['question'] ?? '';
    }

    /**
     * Extract the merchant answer text from the API response.
     */
    protected function extractAnswer(array $question): ?string
    {
        // Try conversations array (from=Merchant)
        $conversations = $question['conversations'] ?? [];
        foreach (array_reverse($conversations) as $conv) {
            if (($conv['from'] ?? '') === 'Merchant') {
                return $conv['content'] ?? null;
            }
        }

        // Fallback to answer object
        return $question['answer']['text'] ?? $question['answer']['content'] ?? null;
    }

    /**
     * Extract the answer date from the API response.
     */
    protected function extractAnswerDate(array $question): Carbon
    {
        // Try conversations array (from=Merchant)
        $conversations = $question['conversations'] ?? [];
        foreach (array_reverse($conversations) as $conv) {
            if (($conv['from'] ?? '') === 'Merchant' && isset($conv['createdAt'])) {
                return $this->parseTimestamp($conv['createdAt']);
            }
        }

        return $this->parseTimestamp(
            $question['answer']['createdAt'] ?? $question['answer']['createdDate'] ?? $question['lastModifiedAt'] ?? null
        );
    }

    /**
     * Check if the question is answered (supports both string and int status)
     */
    protected function isAnswered(array $question): bool
    {
        $status = $question['status'] ?? null;

        if (is_int($status)) {
            return $status === 2;
        }

        return in_array($status, ['Answered', 'answered', 2, '2'], true);
    }

    /**
     * Save Q&A pair as AI training data
     */
    protected function saveTrainingData(Conversation $conversation, array $question): void
    {
        $questionText = $this->extractCustomerQuestion($question);
        $answerText = $this->extractAnswer($question) ?? '';

        if (mb_strlen($questionText) < 5 || mb_strlen($answerText) < 3) {
            return;
        }

        $exists = AiTrainingData::withoutGlobalScopes()
            ->where('source_type', 'hepsiburada')
            ->where('source_id', $conversation->id)
            ->exists();

        if ($exists) {
            return;
        }

        $productName = $question['product']['name'] ?? $question['name'] ?? $question['productName'] ?? null;
        if ($productName) {
            $questionText = "[{$productName}]\n\n{$questionText}";
        }

        $issueNumber = $question['issueNumber'] ?? $question['number'] ?? $question['id'] ?? null;

        AiTrainingData::create([
            'tenant_id' => $conversation->tenant_id,
            'source_type' => 'hepsiburada',
            'source_id' => $conversation->id,
            'channel_type' => 'hepsiburada-product',
            'question' => $questionText,
            'answer' => $answerText,
            'context' => [
                'conversation_id' => $conversation->id,
                'product_name' => $productName,
                'product_sku' => $question['product']['sku'] ?? $question['sku'] ?? $question['stockCode'] ?? null,
                'hepsiburada_question_id' => $issueNumber,
                'source_label' => 'Hepsiburada Ürün Sorusu',
            ],
            'quality_score' => 0.75,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $this->totalTraining++;
    }

    /**
     * Map Hepsiburada status to internal status.
     * Supports both integer (1,2,3,4) and string (WaitingforAnswer, Answered, etc.) formats.
     */
    protected function mapStatus(int|string $status): string
    {
        if (is_int($status) || is_numeric($status)) {
            return match ((int) $status) {
                2 => 'resolved',
                3, 4 => 'closed',
                default => 'open',
            };
        }

        return match ($status) {
            'Answered', 'answered' => 'resolved',
            'Rejected', 'rejected', 'AutoClosed', 'autoClosed' => 'closed',
            default => 'open',
        };
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

    /**
     * Parse ISO 8601 timestamp or return now()
     */
    protected function parseTimestamp(?string $timestamp): Carbon
    {
        if (! $timestamp) {
            return now();
        }

        try {
            return Carbon::parse($timestamp);
        } catch (\Exception $e) {
            return now();
        }
    }
}
