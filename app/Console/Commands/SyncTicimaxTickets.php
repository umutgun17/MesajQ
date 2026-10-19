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
use App\Services\TicimaxService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncTicimaxTickets extends Command
{
    protected $signature = 'ticimax:sync-tickets
                            {--limit=50 : Sayfa basi kayit sayisi}
                            {--pages=0 : Max sayfa (0=hepsi)}
                            {--resync : Acik talepleri kontrol et}
                            {--fill-gaps : Eksik ticket ID bosluklarini tek tek cek}';

    protected $description = 'Ticimax destek taleplerini senkronize eder (cift yonlu)';

    protected int $totalSynced = 0;
    protected int $totalUpdated = 0;
    protected int $totalSkipped = 0;
    protected int $totalTraining = 0;

    public function handle(): int
    {
        $channels = Channel::withoutGlobalScopes()
            ->where('type', 'ticimax-ticket')
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif ticimax-ticket kanali bulunamadi.');
            return 1;
        }

        foreach ($channels as $channel) {
            $service = TicimaxService::fromChannel($channel);

            if (! $service->isAvailable()) {
                $this->warn("Kanal #{$channel->id} (Tenant #{$channel->tenant_id}): Credential eksik, atlaniyor.");
                continue;
            }

            $this->info("Kanal #{$channel->id} (Tenant #{$channel->tenant_id}) isleniyor...");

            $tenantId = $channel->tenant_id;

            if ($this->option('fill-gaps')) {
                $this->fillGaps($service, $channel, $tenantId);
                continue;
            }

            if ($this->option('resync')) {
                $this->resyncOpenTickets($service, $channel, $tenantId);
            }

            $this->pullTickets($service, $channel, $tenantId);

            $channel->update(['last_synced_at' => now()]);
        }

        $this->info("Tamamlandi: {$this->totalSynced} yeni, {$this->totalUpdated} guncellendi, {$this->totalSkipped} mevcut, {$this->totalTraining} egitim verisi");
        Log::info('SyncTicimaxTickets: completed', [
            'synced' => $this->totalSynced,
            'updated' => $this->totalUpdated,
            'skipped' => $this->totalSkipped,
            'training' => $this->totalTraining,
        ]);

        return 0;
    }

    /**
     * Pull tickets from Ticimax API and sync to local DB.
     */
    protected function pullTickets(TicimaxService $ticimaxService, Channel $channel, int $tenantId): void
    {
        $limit = (int) $this->option('limit');
        $maxPages = (int) $this->option('pages');

        $page = 1;
        while (true) {
            $result = $ticimaxService->getTickets(-1, -1, $page, $limit);

            if (! $result['success']) {
                $this->warn("API hatasi (sayfa {$page}): " . ($result['error'] ?? 'Bilinmeyen hata') . ' — atlaniyor');
                $page++;

                if ($maxPages > 0 && ($page - 1) >= $maxPages) {
                    break;
                }

                usleep(300000);
                continue;
            }

            $data = $result['data'] ?? [];

            // The SOAP response structure may nest tickets under a result key
            $tickets = $this->extractTickets($data);

            if (empty($tickets)) {
                if ($page === 1) {
                    $this->info('Hic ticket bulunamadi.');
                }
                break;
            }

            foreach ($tickets as $ticket) {
                $this->syncTicket($ticimaxService, $ticket, $channel, $tenantId);
            }

            $this->info("Sayfa {$page}: " . count($tickets) . " ticket islendi");

            $page++;

            if ($maxPages > 0 && ($page - 1) >= $maxPages) {
                break;
            }

            // If fewer records returned than limit, we've reached the last page
            if (count($tickets) < $limit) {
                break;
            }

            usleep(300000); // 0.3s rate limit between pages
        }
    }

    /**
     * Re-sync: check our open/pending conversations for new messages from Ticimax.
     */
    protected function resyncOpenTickets(TicimaxService $ticimaxService, Channel $channel, int $tenantId): void
    {
        $this->info('Resync: Acik talepler kontrol ediliyor...');

        // Resync open/pending/resolved conversations with recent activity (last 30 days)
        $openConversations = Conversation::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->whereIn('status', ['open', 'pending', 'resolved'])
            ->where('last_message_at', '>=', now()->subDays(30))
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get();

        if ($openConversations->isEmpty()) {
            $this->info('Resync: Son 30 gunde aktif talep yok.');
            return;
        }

        $this->info("Resync: {$openConversations->count()} konusma kontrol ediliyor...");

        foreach ($openConversations as $conversation) {
            $metadata = $conversation->metadata ?? [];
            $ticketId = $metadata['ticimax_ticket_id'] ?? null;

            if (! $ticketId) {
                continue;
            }

            // Fetch messages for this ticket
            $result = $ticimaxService->getTicketMessages((int) $ticketId);

            if (! $result['success']) {
                continue;
            }

            $messages = $this->extractMessages($result['data'] ?? []);

            if (empty($messages)) {
                continue;
            }

            // Get the initial ticket message content for duplicate detection
            $initialMessage = Message::where('conversation_id', $conversation->id)
                ->where('platform_message_id', "tcm_q_{$ticketId}")
                ->first();
            $initialContent = null;
            if ($initialMessage) {
                $initialContent = $initialMessage->content;
                // Strip [Konu] prefix for comparison
                if (preg_match('/^\[.*?\]\n\n(.+)$/s', $initialContent, $m)) {
                    $initialContent = trim($m[1]);
                } else {
                    $initialContent = trim($initialContent);
                }
            }

            $skippedInitial = false;
            $newCount = 0;

            foreach ($messages as $msg) {
                $msgId = $msg['ID'] ?? null;
                if (! $msgId) {
                    continue;
                }

                $platformMsgId = "tcm_m_{$msgId}";
                $exists = Message::where('conversation_id', $conversation->id)
                    ->where('platform_message_id', $platformMsgId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $senderType = $this->determineSenderType($msg);
                $content = $msg['Cevap'] ?? $msg['Mesaj'] ?? '';

                // Skip agent messages already sent from panel (tcm_answered_*)
                if ($senderType === 'agent' && ! empty($content)) {
                    $normalizedContent = $this->normalizeForCompare($content);
                    $panelMessage = Message::where('conversation_id', $conversation->id)
                        ->where('platform_message_id', 'like', 'tcm_answered_%')
                        ->get(['id', 'content']);
                    $matchedMsg = $panelMessage->first(fn ($m) => $this->normalizeForCompare($m->content) === $normalizedContent);
                    if ($matchedMsg) {
                        $matchedMsg->update(['platform_message_id' => $platformMsgId]);
                        continue;
                    }
                }

                if (empty($content)) {
                    continue;
                }

                // Skip the first customer message that duplicates the initial ticket message (tcm_q_*)
                if (! $skippedInitial && $senderType === 'customer') {
                    $skippedInitial = true;
                    continue;
                }

                // Append file URL if present
                $fileUrl = $msg['FileUrl'] ?? null;
                if (! empty($fileUrl)) {
                    $content .= "\n\n[Ek dosya: {$fileUrl}]";
                }

                $msgTimestamp = $msg['KonusmaTarihi'] ?? null;

                Message::create([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversation->id,
                    'sender_type' => $senderType,
                    'sender_id' => null,
                    'content' => $content,
                    'platform_message_id' => $platformMsgId,
                    'metadata' => [
                        'ticimax_message_id' => $msgId,
                        'ticimax_ticket_id' => $ticketId,
                        'synced_from' => 'ticimax',
                    ],
                    'created_at' => $this->parseTimestamp($msgTimestamp),
                ]);

                $newCount++;
            }

            if ($newCount > 0) {
                // Update last_message_at to the latest Ticimax message with a valid timestamp
                $lastTicimaxMsg = collect($messages)
                    ->filter(fn($m) => ! empty($m['KonusmaTarihi']))
                    ->sortByDesc('KonusmaTarihi')
                    ->first();

                if ($lastTicimaxMsg) {
                    $conversation->update([
                        'last_message_at' => $this->parseTimestamp($lastTicimaxMsg['KonusmaTarihi']),
                    ]);
                }

                // Determine status based on latest message sender
                $latestMsg = $conversation->messages()->orderByDesc('created_at')->first();
                if ($latestMsg && $latestMsg->sender_type === 'customer') {
                    // Customer sent a new message → reopen & mark unread, regenerate AI suggestion
                    $conversation->update([
                        'status' => 'open',
                        'is_read' => false,
                        'unread_count' => $conversation->unread_count + $newCount,
                        'ai_suggestion' => null,
                        'ai_suggestion_confidence' => null,
                    ]);

                    GenerateAiSuggestion::dispatch($conversation->id, $tenantId)
                        ->delay(now()->addSeconds(5));
                } elseif ($latestMsg && $latestMsg->sender_type === 'agent') {
                    // Agent replied from Ticimax panel → mark resolved
                    $conversation->update([
                        'status' => 'resolved',
                    ]);
                }

                $this->totalUpdated++;
                $this->line("  + Ticket #{$ticketId}: {$newCount} yeni mesaj");
            }

            usleep(200000); // 0.2s between API calls
        }
    }

    /**
     * Sync a single ticket and its message thread.
     */
    protected function syncTicket(TicimaxService $ticimaxService, array $ticket, Channel $channel, int $tenantId): void
    {
        $ticketId = $ticket['ID'] ?? 0;
        if (! $ticketId) {
            return;
        }

        $uyeId = (string) ($ticket['UyeID'] ?? 0);
        $uyeAdi = $ticket['UyeAdi'] ?? 'Ticimax Musteri';

        // Find or create customer
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform_id' => $uyeId,
                'platform_type' => 'ticimax',
            ],
            [
                'name' => $uyeAdi,
            ]
        );

        // Check if conversation exists
        $platformConvId = "tcm_{$ticketId}";
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel_id', $channel->id)
            ->where('platform_conversation_id', $platformConvId)
            ->first();

        $isNew = false;
        $statusMap = $this->mapStatus($ticket);

        if (! $conversation) {
            $isNew = true;
            $conversation = Conversation::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'platform_conversation_id' => $platformConvId,
                'customer_id' => $customer->id,
                'type' => 'message',
                'status' => $statusMap,
                'last_message_at' => $this->parseTimestamp($ticket['EklemeTarihi'] ?? null),
                'metadata' => [
                    'ticimax_ticket_id' => $ticketId,
                    'konu' => $ticket['Konu'] ?? null,
                    'konu_id' => $ticket['KonuID'] ?? null,
                    'sorumlu' => $ticket['SorumluAdi'] ?? null,
                    'durum' => $ticket['Durum'] ?? null,
                    'ekleme_tarihi' => $ticket['EklemeTarihi'] ?? null,
                    'uye_id' => $ticket['UyeID'] ?? null,
                ],
            ]);
        } else {
            // Update status if changed
            $newStatus = $statusMap;
            if ($conversation->status !== $newStatus && $conversation->status !== 'resolved') {
                $conversation->update(['status' => $newStatus]);
            }

            // Backfill missing metadata fields (ekleme_tarihi, uye_id)
            $existingMeta = $conversation->metadata ?? [];
            if (empty($existingMeta['ekleme_tarihi']) || empty($existingMeta['uye_id'])) {
                $conversation->update([
                    'metadata' => array_merge($existingMeta, [
                        'ekleme_tarihi' => $existingMeta['ekleme_tarihi'] ?? ($ticket['EklemeTarihi'] ?? null),
                        'uye_id' => $existingMeta['uye_id'] ?? ($ticket['UyeID'] ?? null),
                        'durum' => $ticket['Durum'] ?? ($existingMeta['durum'] ?? null),
                    ]),
                ]);
            }

            // Backfill training data for existing conversations that don't have it yet
            $hasTrainingData = AiTrainingData::withoutGlobalScopes()
                ->where('source_type', 'ticimax')
                ->where('source_id', $conversation->id)
                ->exists();

            if (! $hasTrainingData) {
                $threadResult = $ticimaxService->getTicketMessages((int) $ticketId);
                if ($threadResult['success']) {
                    $messages = $this->extractMessages($threadResult['data'] ?? []);
                    $lastAgentContent = null;
                    foreach ($messages as $msg) {
                        if ($this->determineSenderType($msg) === 'agent') {
                            $content = $msg['Cevap'] ?? $msg['Mesaj'] ?? '';
                            if (! empty($content)) {
                                $lastAgentContent = $content;
                            }
                        }
                    }
                    if ($lastAgentContent) {
                        $this->saveTrainingData($conversation, $ticket, $lastAgentContent);
                    }
                }
                usleep(200000);
            }

            $this->totalSkipped++;
            return;
        }

        // Create the initial ticket message (from customer)
        $initialMsg = $ticket['Mesaj'] ?? '';
        $konu = $ticket['Konu'] ?? '';
        $fileUrl = $ticket['FileUrl'] ?? null;

        $content = '';
        if (! empty($konu)) {
            $content = "[{$konu}]\n\n";
        }
        $content .= ! empty($initialMsg) ? $initialMsg : '(Mesaj icerigi yok)';
        if (! empty($fileUrl)) {
            $content .= "\n\n[Ek dosya: {$fileUrl}]";
        }

        $initialPlatformId = "tcm_q_{$ticketId}";
        $initialExists = Message::where('conversation_id', $conversation->id)
            ->where('platform_message_id', $initialPlatformId)
            ->exists();

        if (! $initialExists) {
            Message::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'content' => $content,
                'platform_message_id' => $initialPlatformId,
                'metadata' => [
                    'ticimax_ticket_id' => $ticketId,
                    'konu' => $konu,
                ],
                'created_at' => $this->parseTimestamp($ticket['EklemeTarihi'] ?? null),
            ]);
        }

        // Fetch the full message thread for this ticket
        $threadResult = $ticimaxService->getTicketMessages($ticketId);

        if ($threadResult['success']) {
            $messages = $this->extractMessages($threadResult['data'] ?? []);
            $hasAgentReply = false;
            $lastAgentContent = null;
            $skippedInitial = false;

            foreach ($messages as $msg) {
                $msgId = $msg['ID'] ?? null;
                if (! $msgId) {
                    continue;
                }

                // Skip the first customer message in thread — it duplicates the initial ticket message (tcm_q_*)
                $senderType = $this->determineSenderType($msg);
                $msgContent = $msg['Cevap'] ?? $msg['Mesaj'] ?? '';
                if (! $skippedInitial && $senderType === 'customer') {
                    $skippedInitial = true;
                    continue;
                }

                $platformMsgId = "tcm_m_{$msgId}";
                $msgExists = Message::where('conversation_id', $conversation->id)
                    ->where('platform_message_id', $platformMsgId)
                    ->exists();

                if ($msgExists) {
                    continue;
                }

                if (empty($msgContent)) {
                    continue;
                }

                // Skip agent messages already sent from panel (tcm_answered_*)
                if ($senderType === 'agent') {
                    $normalizedContent = $this->normalizeForCompare($msgContent);
                    $panelMessage = Message::where('conversation_id', $conversation->id)
                        ->where('platform_message_id', 'like', 'tcm_answered_%')
                        ->get(['id', 'content']);
                    $matchedMsg = $panelMessage->first(fn ($m) => $this->normalizeForCompare($m->content) === $normalizedContent);
                    if ($matchedMsg) {
                        $matchedMsg->update(['platform_message_id' => $platformMsgId]);
                        continue;
                    }
                }

                $msgFileUrl = $msg['FileUrl'] ?? null;
                if (! empty($msgFileUrl)) {
                    $msgContent .= "\n\n[Ek dosya: {$msgFileUrl}]";
                }

                Message::create([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversation->id,
                    'sender_type' => $senderType,
                    'sender_id' => null,
                    'content' => $msgContent,
                    'platform_message_id' => $platformMsgId,
                    'metadata' => [
                        'ticimax_message_id' => $msgId,
                        'ticimax_ticket_id' => $ticketId,
                        'synced_from' => 'ticimax',
                    ],
                    'created_at' => $this->parseTimestamp($msg['KonusmaTarihi'] ?? null),
                ]);

                if ($senderType === 'agent') {
                    $hasAgentReply = true;
                    $lastAgentContent = $msgContent;
                }
            }

            // Update last_message_at to the latest Ticimax message with a valid timestamp
            $lastTicimaxMsg = collect($messages)
                ->filter(fn($m) => ! empty($m['KonusmaTarihi']))
                ->sortByDesc('KonusmaTarihi')
                ->first();

            if ($lastTicimaxMsg) {
                $conversation->update([
                    'last_message_at' => $this->parseTimestamp($lastTicimaxMsg['KonusmaTarihi']),
                ]);
            }

            if ($statusMap === 'resolved') {
                $conversation->update([
                    'status' => 'resolved',
                    'is_read' => true,
                    'unread_count' => 0,
                ]);
            } else {
                $conversation->update([
                    'is_read' => false,
                    'unread_count' => max(1, $conversation->messages()->where('sender_type', 'customer')->count()),
                ]);
            }

            // Save training data from any ticket with agent reply
            if ($hasAgentReply && $lastAgentContent) {
                $this->saveTrainingData($conversation, $ticket, $lastAgentContent);
            }

            // Dispatch auto-reply for new open tickets without agent reply
            if (! $hasAgentReply && $statusMap !== 'resolved') {
                $this->dispatchAutoReply($conversation, $tenantId);

                // Pre-generate AI suggestion
                GenerateAiSuggestion::dispatch($conversation->id, $tenantId)
                    ->delay(now()->addSeconds(5));
            }
        } else {
            // Could not fetch messages, mark conversation as unread
            $conversation->update([
                'is_read' => false,
                'unread_count' => 1,
            ]);

            // Still try auto-reply for open tickets
            if ($statusMap !== 'resolved') {
                $this->dispatchAutoReply($conversation, $tenantId);
            }
        }

        $this->totalSynced++;

        usleep(200000); // 0.2s between ticket detail calls
    }

    /**
     * Determine sender type from a Ticimax message.
     * If UyeID > 0 or Cevaplayan matches customer, it's customer. Otherwise agent.
     */
    protected function determineSenderType(array $msg): string
    {
        // CevaplayanStr contains sender info. If it's the customer (UyeAdi), sender = customer
        // Ticimax convention: Cevaplayan = 0 or UyeID match = customer, otherwise agent/staff
        $cevaplayan = $msg['Cevaplayan'] ?? null;
        $uyeId = $msg['UyeID'] ?? null;

        // If Cevaplayan equals UyeID, it's from the customer
        if ($cevaplayan !== null && $uyeId !== null && (int) $cevaplayan === (int) $uyeId && (int) $uyeId > 0) {
            return 'customer';
        }

        // If CevaplayanStr contains "Müşteri" or matches UyeAdi, it's customer
        $cevaplayanStr = $msg['CevaplayanStr'] ?? '';
        $uyeAdi = $msg['UyeAdi'] ?? '';

        if (! empty($uyeAdi) && ! empty($cevaplayanStr) && $cevaplayanStr === $uyeAdi) {
            return 'customer';
        }

        // Default: if Cevaplayan is 0 or null and UyeID > 0, likely customer's initial message
        if ((int) ($cevaplayan ?? 0) === 0 && (int) ($uyeId ?? 0) > 0) {
            return 'customer';
        }

        return 'agent';
    }

    /**
     * Normalize content for comparison — Ticimax strips quotes and special chars.
     */
    protected function normalizeForCompare(string $content): string
    {
        // Remove quotes, extra whitespace, normalize line endings
        $content = preg_replace('/[\x{201C}\x{201D}\x{201E}\x{201F}"\x{2018}\x{2019}\x{00AB}\x{00BB}]/u', '', $content);
        $content = preg_replace('/\s+/', ' ', $content);

        return trim($content);
    }

    /**
     * Extract tickets array from SOAP response data.
     * WSDL: GetSupportTicketsResult -> DestekTalepleri -> ServisMusteriTalep[]
     */
    protected function extractTickets(array $data): array
    {
        // Primary path: GetSupportTicketsResult.DestekTalepleri.ServisMusteriTalep
        $root = $data['GetSupportTicketsResult'] ?? $data['GetAllSupportTicketsResult'] ?? $data;

        $talepleri = $root['DestekTalepleri'] ?? null;

        if (is_array($talepleri)) {
            // Check for ServisMusteriTalep wrapper (SOAP array of complex type)
            if (isset($talepleri['ServisMusteriTalep'])) {
                $items = $talepleri['ServisMusteriTalep'];
                // Single item comes as object, multiple as array
                return isset($items['ID']) ? [$items] : $items;
            }

            // Direct array of tickets
            if (isset($talepleri[0]['ID'])) {
                return $talepleri;
            }

            // Single ticket directly in DestekTalepleri
            if (isset($talepleri['ID'])) {
                return [$talepleri];
            }
        }

        return [];
    }

    /**
     * Extract messages array from SOAP response data.
     * WSDL: GetSupportTicketMessagesResult -> DestekMesajlari -> ServisMusteriCevap[]
     */
    protected function extractMessages(array $data): array
    {
        $root = $data['GetSupportTicketMessagesResult'] ?? $data;

        $mesajlari = $root['DestekMesajlari'] ?? null;

        if (is_array($mesajlari)) {
            // Check for ServisMusteriCevap wrapper
            if (isset($mesajlari['ServisMusteriCevap'])) {
                $items = $mesajlari['ServisMusteriCevap'];
                return isset($items['ID']) ? [$items] : $items;
            }

            if (isset($mesajlari[0]['ID'])) {
                return $mesajlari;
            }

            if (isset($mesajlari['ID'])) {
                return [$mesajlari];
            }
        }

        return [];
    }

    /**
     * Map Ticimax ticket status to internal status.
     */
    protected function mapStatus(array $ticket): string
    {
        $cozuldu = $ticket['Cozuldu'] ?? false;
        if ($cozuldu === true || $cozuldu === 'true' || $cozuldu === 1) {
            return 'resolved';
        }

        $durumId = $ticket['DurumID'] ?? null;
        $durum = mb_strtolower($ticket['Durum'] ?? '');

        // Closed / resolved / answered states
        // DurumID: 2 = Cevaplandı, 3-5 = Kapandı/Çözüldü
        if (in_array($durumId, [2, 3, 4, 5], true) || str_contains($durum, 'kapan') || str_contains($durum, 'çözül') || str_contains($durum, 'cevapla')) {
            return 'resolved';
        }

        // Waiting / in-progress
        if (str_contains($durum, 'bekl') || str_contains($durum, 'işlem')) {
            return 'pending';
        }

        return 'open';
    }

    /**
     * Save Q&A pair as AI training data.
     */
    protected function saveTrainingData(Conversation $conversation, array $ticket, ?string $agentAnswer): void
    {
        $questionText = $ticket['Mesaj'] ?? '';
        $konu = $ticket['Konu'] ?? '';

        if (! empty($konu)) {
            $questionText = "[{$konu}]\n\n{$questionText}";
        }

        if (mb_strlen($questionText) < 5 || empty($agentAnswer) || mb_strlen($agentAnswer) < 3) {
            return;
        }

        $exists = AiTrainingData::withoutGlobalScopes()
            ->where('source_type', 'ticimax')
            ->where('source_id', $conversation->id)
            ->exists();

        if ($exists) {
            return;
        }

        $ticketId = $ticket['ID'] ?? null;

        AiTrainingData::create([
            'tenant_id' => $conversation->tenant_id,
            'source_type' => 'ticimax',
            'source_id' => $conversation->id,
            'channel_type' => 'ticimax-ticket',
            'question' => $questionText,
            'answer' => $agentAnswer,
            'context' => [
                'conversation_id' => $conversation->id,
                'konu' => $konu,
                'ticimax_ticket_id' => $ticketId,
                'source_label' => 'Ticimax Destek Talebi',
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

    /**
     * Fill gaps: find missing ticket IDs in DB and fetch them individually by ID.
     * Handles tickets skipped due to SOAP base64 errors in bulk pages.
     */
    protected function fillGaps(TicimaxService $ticimaxService, Channel $channel, int $tenantId): void
    {
        // Get all existing ticket IDs from platform_conversation_id
        $existingIds = Conversation::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->pluck('platform_conversation_id')
            ->map(fn($id) => (int) str_replace('tcm_', '', $id))
            ->filter()
            ->sort()
            ->values();

        if ($existingIds->isEmpty()) {
            $this->info('Fill-gaps: Ticket bulunamadi.');
            return;
        }

        $minId = $existingIds->first();
        $maxId = $existingIds->last();

        $this->info("Fill-gaps: Ticket ID araligi {$minId} - {$maxId}, DB'de {$existingIds->count()} ticket mevcut");

        // Calculate missing IDs
        $allIds = collect(range($minId, $maxId));
        $existingSet = $existingIds->flip();
        $missingIds = $allIds->reject(fn($id) => $existingSet->has($id))->values();

        if ($missingIds->isEmpty()) {
            $this->info('Fill-gaps: Eksik ticket yok, tum araliktaki ticketlar mevcut.');
            return;
        }

        $this->info("Fill-gaps: {$missingIds->count()} eksik ticket bulundu, tek tek cekilecek...");

        $synced = 0;
        $errors = 0;
        $notFound = 0;

        foreach ($missingIds as $ticketId) {
            // Fetch this specific ticket by ID
            $result = $ticimaxService->getTickets($ticketId, -1, 1, 1);

            if (! $result['success']) {
                $errors++;
                $this->warn("  x Ticket #{$ticketId}: API hatasi — " . ($result['error'] ?? 'bilinmeyen'));
                usleep(300000);
                continue;
            }

            $tickets = $this->extractTickets($result['data'] ?? []);

            if (empty($tickets)) {
                $notFound++;
                $this->line("  - Ticket #{$ticketId}: API'de bulunamadi (silinmis olabilir)");
                usleep(200000);
                continue;
            }

            $ticket = $tickets[0];
            $this->syncTicket($ticimaxService, $ticket, $channel, $tenantId);
            $synced++;
            $this->info("  + Ticket #{$ticketId} eklendi");

            usleep(300000); // 0.3s rate limit
        }

        $this->info("Fill-gaps tamamlandi: {$synced} eklendi, {$notFound} bulunamadi, {$errors} hata");
    }

    /**
     * Parse Ticimax timestamp (Turkey local time) and convert to UTC.
     * Ticimax API returns timestamps without timezone info in Europe/Istanbul (UTC+3).
     */
    protected function parseTimestamp(?string $timestamp): Carbon
    {
        if (! $timestamp) {
            return now();
        }

        try {
            // If timestamp has timezone indicator (Z or +offset), parse as-is
            if (str_contains($timestamp, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/', $timestamp)) {
                return Carbon::parse($timestamp)->utc();
            }

            // Ticimax timestamps are Turkey local time — convert to UTC
            return Carbon::parse($timestamp, 'Europe/Istanbul')->utc();
        } catch (\Exception $e) {
            return now();
        }
    }
}
