<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiSuggestion;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Services\EmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEmailMessages extends Command
{
    protected $signature = 'email:sync
        {--channel= : Belirli kanal ID}
        {--limit=50 : Her kanal için max e-posta sayısı}
        {--days= : Son kaç günü tara (varsayılan: last_synced_at veya 3 gün)}
        {--reclassify : other kategorisindeki mailleri yeniden sınıflandır}';

    protected $description = 'IMAP ile e-postaları çek, AI ile sınıflandır';

    protected int $created = 0;
    protected int $skipped = 0;
    protected int $classified = 0;
    protected int $sentProcessed = 0;
    protected int $sentSkipped = 0;

    public function handle(): int
    {
        if ($this->option('reclassify')) {
            return $this->reclassifyOthers();
        }

        $channelId = $this->option('channel');
        $limit = (int) $this->option('limit');
        $days = $this->option('days') ? (int) $this->option('days') : null;

        $query = Channel::withoutGlobalScopes()
            ->where('type', 'email')
            ->where('is_active', true);

        if ($channelId) {
            $query->where('id', $channelId);
        }

        $channels = $query->get();

        if ($channels->isEmpty()) {
            $this->info('Aktif e-posta kanalı bulunamadı.');
            return 0;
        }

        foreach ($channels as $channel) {
            $this->syncChannel($channel, $limit, $days);
        }

        $this->info("Tamamlandı: {$this->created} yeni kayıt, {$this->classified} sınıflandırıldı, {$this->skipped} atlandı, Sent: {$this->sentProcessed} işlendi / {$this->sentSkipped} atlandı");

        return 0;
    }

    /**
     * Reclassify emails that were categorized as 'other' (excluding bounces)
     */
    protected function reclassifyOthers(): int
    {
        $emails = EmailMessage::withoutGlobalScopes()
            ->where('ai_category', 'other')
            ->whereNull('conversation_id')
            ->where('from_email', 'not like', '%mailer-daemon%')
            ->where('from_email', 'not like', '%postmaster%')
            ->orderBy('id')
            ->get();

        $this->info("Yeniden sınıflandırılacak: {$emails->count()} e-posta");

        $reclassified = 0;
        $promoted = 0;

        foreach ($emails as $emailMsg) {
            $channel = Channel::withoutGlobalScopes()->find($emailMsg->channel_id);
            if (! $channel) {
                continue;
            }

            $service = new EmailService($channel);
            $newCategory = $service->classifyEmail(
                $emailMsg->subject ?? '',
                $emailMsg->body_text ?? '',
                $emailMsg->from_email ?? ''
            );

            if ($newCategory === $emailMsg->ai_category) {
                continue;
            }

            $reclassified++;
            $emailMsg->update(['ai_category' => $newCategory]);

            if ($newCategory === 'customer_request') {
                $promoted++;

                $emailData = [
                    'message_id' => $emailMsg->message_id,
                    'in_reply_to' => $emailMsg->in_reply_to,
                    'references' => $emailMsg->references,
                    'from_email' => $emailMsg->from_email,
                    'from_name' => $emailMsg->from_name,
                    'to_email' => $emailMsg->to_email,
                    'subject' => $emailMsg->subject,
                    'body_text' => $emailMsg->body_text,
                    'body_html' => $emailMsg->body_html,
                    'date' => $emailMsg->created_at->toDateTimeString(),
                ];

                $conversation = $service->findOrCreateConversation($emailData, $channel);
                $emailMsg->update(['conversation_id' => $conversation->id]);

                Message::create([
                    'tenant_id' => $channel->tenant_id,
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'customer',
                    'sender_id' => null,
                    'content' => $this->buildMessageContent($emailData),
                    'platform_message_id' => $emailMsg->message_id,
                ]);

                $conversation->update([
                    'status' => 'open',
                    'is_read' => false,
                    'unread_count' => ($conversation->unread_count ?? 0) + 1,
                    'last_message_at' => $emailData['date'],
                ]);

                $this->line("  [PROMOTED] {$emailMsg->from_email}: {$emailMsg->subject}");
            } else {
                $this->line("  [RECLASSIFIED] {$emailMsg->from_email}: {$emailMsg->ai_category} → {$newCategory}");
            }
        }

        $this->info("Tamamlandı: {$reclassified} yeniden sınıflandırıldı, {$promoted} konuşmaya dönüştürüldü");

        return 0;
    }

    protected function syncChannel(Channel $channel, int $limit, ?int $days = null): void
    {
        $this->info("Kanal: {$channel->name} (ID: {$channel->id})");

        $service = new EmailService($channel);
        if (! $service->isAvailable()) {
            $this->warn('  IMAP bilgileri eksik, atlanıyor.');
            return;
        }

        $emails = $service->fetchNewEmails($limit, $days);
        $count = count($emails);
        $this->info("  {$count} e-posta çekildi.");

        if (empty($emails)) {
            $channel->update(['last_synced_at' => now()]);
            return;
        }

        foreach ($emails as $emailData) {
            $this->processEmail($emailData, $channel, $service);
        }

        $channel->update(['last_synced_at' => now()]);

        // Sync Sent folder for outgoing email detection
        $this->syncSentFolder($channel, $service);
    }

    protected function processEmail(array $emailData, Channel $channel, EmailService $service): void
    {
        $messageId = $emailData['message_id'] ?? null;
        if (! $messageId) {
            $this->skipped++;
            return;
        }

        // Duplicate check
        $exists = EmailMessage::withoutGlobalScopes()
            ->where('message_id', $messageId)
            ->exists();

        if ($exists) {
            $this->skipped++;
            return;
        }

        // Rule-based classification
        $category = $service->classifyEmail(
            $emailData['subject'] ?? '',
            $emailData['body_text'] ?? '',
            $emailData['from_email'] ?? ''
        );
        $this->classified++;

        $conversationId = null;

        // Only create conversation + message for customer_request
        if ($category === 'customer_request') {
            $conversation = $service->findOrCreateConversation($emailData, $channel);
            $conversationId = $conversation->id;

            // Create Message record
            Message::create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'content' => $this->buildMessageContent($emailData),
                'platform_message_id' => $messageId,
            ]);

            // Update conversation
            $conversation->update([
                'status' => 'open',
                'is_read' => false,
                'unread_count' => ($conversation->unread_count ?? 0) + 1,
                'last_message_at' => $emailData['date'] ?? now(),
                'ai_suggestion' => null,
                'ai_suggestion_confidence' => null,
            ]);

            // Pre-generate AI suggestion
            GenerateAiSuggestion::dispatch($conversation->id, $channel->tenant_id)
                ->delay(now()->addSeconds(5));
        }

        // Save all emails to email_messages (audit trail)
        EmailMessage::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'message_id' => $messageId,
            'in_reply_to' => $emailData['in_reply_to'] ?? null,
            'references' => $emailData['references'] ?? null,
            'from_email' => $emailData['from_email'],
            'from_name' => $emailData['from_name'] ?? null,
            'to_email' => $emailData['to_email'],
            'subject' => $emailData['subject'] ?? null,
            'body_text' => $emailData['body_text'] ?? null,
            'body_html' => $emailData['body_html'] ?? null,
            'ai_category' => $category,
            'conversation_id' => $conversationId,
            'processed_at' => now(),
        ]);

        $this->created++;

        // Bounce detection: mailer-daemon / postmaster emails
        $this->detectBounce($emailData, $channel);

        $label = $category === 'customer_request' ? 'MÜŞTERİ TALEBİ' : strtoupper($category);
        $this->line("  [{$label}] {$emailData['from_email']}: {$emailData['subject']}");
    }

    /**
     * Detect bounce emails and mark related conversations with delivery error
     */
    protected function detectBounce(array $emailData, Channel $channel): void
    {
        $fromEmail = strtolower($emailData['from_email'] ?? '');

        // Only process mailer-daemon / postmaster bounces
        if (! str_contains($fromEmail, 'mailer-daemon') && ! str_contains($fromEmail, 'postmaster')) {
            return;
        }

        $body = $emailData['body_text'] ?? '';
        if (empty($body)) {
            return;
        }

        // Extract failed recipient email from bounce body
        $failedEmail = null;
        if (preg_match('/^\s+(\S+@\S+)/m', $body, $m)) {
            $failedEmail = strtolower(trim($m[1]));
        }

        // Extract SMTP error message
        $errorMsg = null;
        if (preg_match('/SMTP error.*?:\s*(.+?)(?:\n\n|\z)/si', $body, $m)) {
            $errorMsg = trim(preg_replace('/\s+/', ' ', $m[1]));
        } elseif (preg_match('/(5\d{2}[\s-].*?)(?:\n\n|\z)/s', $body, $m)) {
            $errorMsg = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        if (! $failedEmail) {
            return;
        }

        // Find conversations in this channel where we sent to this email
        $conversations = Conversation::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->whereHas('customer', function ($q) use ($failedEmail) {
                $q->where('email', $failedEmail);
            })
            ->orderByDesc('last_message_at')
            ->limit(3)
            ->get();

        foreach ($conversations as $conversation) {
            // Find the last agent message (our reply that bounced)
            $lastAgentMsg = Message::withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->where('sender_type', 'agent')
                ->orderByDesc('created_at')
                ->first();

            if ($lastAgentMsg) {
                $metadata = $lastAgentMsg->metadata ?? [];
                $metadata['delivery_error'] = $errorMsg ?: 'Mail teslim edilemedi';
                $metadata['delivery_error_at'] = now()->toISOString();
                $metadata['bounced_to'] = $failedEmail;
                $lastAgentMsg->update(['metadata' => $metadata]);

                // Bounce = cevap müşteriye ULAŞMADI. Konuşmayı 'resolved' bırakmak
                // hatayı görünmez kılıyordu (4 Eyl 2026: 48 bounce'lu konuşmanın
                // hepsi kapalı görünüyordu). Yeniden aç + okunmamış yap ki personel
                // listede görsün ve yeniden gönderebilsin.
                $conversation->update([
                    'status' => 'open',
                    'is_read' => false,
                ]);

                Log::warning('E-posta cevabı geri döndü (bounce)', [
                    'channel_id' => $channel->id,
                    'conversation_id' => $conversation->id,
                    'to' => $failedEmail,
                    'error' => $metadata['delivery_error'],
                ]);

                $this->warn("  [BOUNCE] {$failedEmail} → Conversation #{$conversation->id} işaretlendi + yeniden açıldı");
            }
        }
    }

    /**
     * Sync Sent folder to detect replies sent from webmail/Outlook
     */
    protected function syncSentFolder(Channel $channel, EmailService $service): void
    {
        $sentFolder = $service->detectSentFolder();
        if (! $sentFolder) {
            $this->line('  Sent klasörü bulunamadı, atlanıyor.');
            return;
        }

        $this->info("  Sent klasörü: {$sentFolder}");

        // Watermark: last_sent_synced_at
        $settings = $channel->settings ?? [];
        $lastSentSync = $settings['last_sent_synced_at'] ?? null;
        $sinceDate = $lastSentSync
            ? date('d-M-Y', strtotime($lastSentSync))
            : now()->subDays(7)->format('d-M-Y');

        $sentEmails = $service->fetchSentEmails($sentFolder, 50, $sinceDate);
        $count = count($sentEmails);
        $this->info("  Sent: {$count} e-posta çekildi.");

        if (empty($sentEmails)) {
            $settings['last_sent_synced_at'] = now()->toISOString();
            $channel->update(['settings' => $settings]);
            return;
        }

        $ourEmail = strtolower(trim($channel->credentials['email_address'] ?? ''));

        foreach ($sentEmails as $emailData) {
            $this->processSentEmail($emailData, $channel, $service, $ourEmail);
        }

        $settings['last_sent_synced_at'] = now()->toISOString();
        $channel->update(['settings' => $settings]);
    }

    /**
     * Process a single sent email
     */
    protected function processSentEmail(array $emailData, Channel $channel, EmailService $service, string $ourEmail): void
    {
        $messageId = $emailData['message_id'] ?? null;
        if (! $messageId) {
            $this->sentSkipped++;
            return;
        }

        // From must be our address
        $fromEmail = strtolower(trim($emailData['from_email'] ?? ''));
        if ($fromEmail !== $ourEmail) {
            $this->sentSkipped++;
            return;
        }

        // Skip self-replies (to = our address)
        $toEmail = strtolower(trim($emailData['to_email'] ?? ''));
        if ($toEmail === $ourEmail) {
            $this->sentSkipped++;
            return;
        }

        // Duplicate check
        $exists = EmailMessage::withoutGlobalScopes()
            ->where('message_id', $messageId)
            ->exists();

        if ($exists) {
            $this->sentSkipped++;
            return;
        }

        // Find matching conversation
        $conversation = $service->findConversationForSentEmail($emailData);
        if (! $conversation) {
            $this->sentSkipped++;
            return;
        }

        // Check if there's already an agent message for this conversation since the last customer message
        $lastCustomerMsg = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'customer')
            ->orderByDesc('created_at')
            ->first();

        $hasAgentReply = false;
        if ($lastCustomerMsg) {
            $hasAgentReply = Message::withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->where('sender_type', 'agent')
                ->where('created_at', '>', $lastCustomerMsg->created_at)
                ->exists();
        }

        // Save outgoing EmailMessage
        EmailMessage::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'message_id' => $messageId,
            'in_reply_to' => $emailData['in_reply_to'] ?? null,
            'references' => $emailData['references'] ?? null,
            'from_email' => $fromEmail,
            'from_name' => $emailData['from_name'] ?? null,
            'to_email' => $toEmail,
            'subject' => $emailData['subject'] ?? null,
            'body_text' => $emailData['body_text'] ?? null,
            'body_html' => $emailData['body_html'] ?? null,
            'ai_category' => 'outgoing_reply',
            'direction' => 'outgoing',
            'conversation_id' => $conversation->id,
            'processed_at' => now(),
        ]);

        // Create agent Message only if no agent reply exists yet
        if (! $hasAgentReply) {
            Message::create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'sender_type' => 'agent',
                'sender_id' => null,
                'content' => $this->buildMessageContent($emailData),
                'platform_message_id' => $messageId,
                'metadata' => ['source' => 'sent_folder_sync'],
            ]);
        }

        // Resolve conversation
        $conversation->update([
            'status' => 'resolved',
            'last_message_at' => $emailData['date'] ?? now(),
        ]);

        $this->sentProcessed++;
        $this->line("  [SENT] → {$toEmail}: {$emailData['subject']}");
    }

    /**
     * Build message content from email data (subject + body)
     */
    protected function buildMessageContent(array $emailData): string
    {
        $parts = [];

        if (! empty($emailData['subject'])) {
            $parts[] = "Konu: {$emailData['subject']}";
        }

        $body = $emailData['body_text'] ?? '';
        if (! empty($body)) {
            $parts[] = $body;
        }

        return implode("\n\n", $parts) ?: '(İçerik yok)';
    }
}
