<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiSuggestion;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncMetaConversations extends Command
{
    protected $signature = 'meta:sync-conversations
                            {--channel= : Channel ID to sync}
                            {--limit=25 : Number of conversations to fetch}';

    protected $description = 'Facebook/Instagram Graph API üzerinden mevcut konuşmaları senkronize eder';

    private string $apiUrl = 'https://graph.facebook.com/v21.0';

    public function handle(): int
    {
        $channelId = $this->option('channel');
        $limit = (int) $this->option('limit');

        $channels = $channelId
            ? Channel::withoutGlobalScopes()->where('id', $channelId)->where('is_active', true)->get()
            : Channel::withoutGlobalScopes()
                ->whereIn('type', ['facebook-message', 'instagram-message'])
                ->where('is_active', true)
                ->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif Facebook/Instagram mesaj kanalı bulunamadı.');
            return 1;
        }

        foreach ($channels as $channel) {
            $this->syncChannel($channel, $limit);
        }

        return 0;
    }

    private function syncChannel(Channel $channel, int $limit): void
    {
        $credentials = $channel->credentials ?? [];
        $pageAccessToken = $credentials['page_access_token']
            ?? config('meta.page_access_token');

        $pageId = $channel->settings['page_id'] ?? null;

        if (! $pageAccessToken || ! $pageId) {
            $this->warn("Kanal #{$channel->id} ({$channel->type}): Token veya page_id eksik, atlanıyor.");
            return;
        }

        $this->info("Kanal #{$channel->id} ({$channel->name} - {$channel->type}) senkronize ediliyor...");

        // Fetch conversations — use 'me/conversations' for Instagram (page_id/conversations requires pages_manage_metadata)
        $isInstagram = str_contains($channel->type, 'instagram');
        $params = [
            'fields' => 'id,participants,messages.limit(5){message,from,created_time,id}',
            'limit' => $limit,
        ];
        if ($isInstagram) {
            $params['platform'] = 'instagram';
        }

        $synced = 0;
        $skipped = 0;
        $totalFetched = 0;
        $pageNum = 0;
        $maxPages = (int) ceil($limit / 25); // Paginate in chunks of 25

        while ($totalFetched < $limit) {
            $pageNum++;
            $params['limit'] = min(10, $limit - $totalFetched);

            try {
                $response = Http::withToken($pageAccessToken)
                    ->timeout(30)
                    ->get("{$this->apiUrl}/me/conversations", $params);
            } catch (\Throwable $e) {
                $this->warn("  Sayfa {$pageNum}: Timeout/bağlantı hatası, durduruluyor.");
                break;
            }

            if ($response->failed()) {
                $this->error("Graph API hatası: " . $response->json('error.message', 'Bilinmeyen hata'));
                Log::error('SyncMetaConversations: API failed', ['status' => $response->status(), 'body' => $response->json()]);
                break;
            }

            $conversations = $response->json('data', []);
            if (empty($conversations)) break;

            $totalFetched += count($conversations);
            $this->info("  Sayfa {$pageNum}: " . count($conversations) . " konuşma (toplam {$totalFetched})");

            foreach ($conversations as $conv) {
                $result = $this->syncConversation($conv, $channel, $pageId, $pageAccessToken);
                if ($result) {
                    $synced++;
                } else {
                    $skipped++;
                }
            }

            // Check for next page
            $nextCursor = $response->json('paging.cursors.after');
            if (! $nextCursor || ! $response->json('paging.next')) break;

            $params['after'] = $nextCursor;
        }

        $this->info("  Sonuç: {$synced} senkronize, {$skipped} atlandı/mevcut (toplam {$totalFetched} konuşma).");
    }

    private function syncConversation(array $conv, Channel $channel, string $pageId, string $token): bool
    {
        $participants = $conv['participants']['data'] ?? [];
        $messages = $conv['messages']['data'] ?? [];

        // Find the customer (non-page participant)
        $customerData = null;
        foreach ($participants as $p) {
            if ($p['id'] !== $pageId) {
                $customerData = $p;
                break;
            }
        }

        if (! $customerData) {
            return false;
        }

        $platformType = str_contains($channel->type, 'instagram') ? 'instagram' : 'facebook';

        // Find or create customer
        $customerName = $customerData['name'] ?? $customerData['username'] ?? 'Meta User';
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'platform_id' => $customerData['id'],
                'platform_type' => $platformType,
            ],
            [
                'name' => $customerName,
            ]
        );

        // Update name if still generic + store Instagram username
        $updateData = [];
        if (in_array($customer->name, ['Meta User', 'Instagram User']) && $customerName !== 'Meta User') {
            $updateData['name'] = $customerName;
        }
        if (! empty($customerData['username'])) {
            $metadata = $customer->metadata ?? [];
            if (empty($metadata['instagram_username'])) {
                $metadata['instagram_username'] = $customerData['username'];
                $updateData['metadata'] = $metadata;
            }
        }
        if (! empty($updateData)) {
            $customer->update($updateData);
        }

        // Create conversation ID
        $platformConvId = $customerData['id'] . '_' . $pageId;

        // Find or create conversation
        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'platform_conversation_id' => $platformConvId,
            ],
            [
                'customer_id' => $customer->id,
                'type' => 'message',
                'status' => 'open',
            ]
        );

        // Sync messages (oldest first)
        $newMessages = 0;
        foreach (array_reverse($messages) as $msg) {
            $mid = $msg['id'] ?? null;
            if (! $mid) continue;

            // Skip if already exists
            if (Message::withoutGlobalScopes()->where('platform_message_id', $mid)->where('conversation_id', $conversation->id)->exists()) {
                continue;
            }

            $isFromPage = ($msg['from']['id'] ?? '') === $pageId;
            $createdTime = isset($msg['created_time']) ? \Carbon\Carbon::parse($msg['created_time']) : now();

            $message = new Message([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'sender_type' => $isFromPage ? 'agent' : 'customer',
                'sender_id' => null,
                'content' => $msg['message'] ?? '',
                'platform_message_id' => $mid,
            ]);
            $message->created_at = $createdTime;
            $message->save();

            $newMessages++;
        }

        // Update conversation timestamps & status based on last message
        if ($newMessages > 0) {
            $lastMsg = collect($messages)->first();
            $lastIsFromPage = ($lastMsg['from']['id'] ?? '') === $pageId;

            if ($lastIsFromPage) {
                $conversation->update([
                    'last_message_at' => $lastMsg['created_time'] ?? now(),
                    'status' => 'resolved',
                ]);
            } else {
                $conversation->update([
                    'last_message_at' => $lastMsg['created_time'] ?? now(),
                    'status' => 'open',
                    'is_read' => false,
                    'unread_count' => $conversation->unread_count + $newMessages,
                    'ai_suggestion' => null,
                    'ai_suggestion_confidence' => null,
                ]);

                // Pre-generate AI suggestion
                GenerateAiSuggestion::dispatch($conversation->id, $channel->tenant_id)
                    ->delay(now()->addSeconds(5));
            }
        }

        if ($newMessages > 0) {
            $this->line("    ✓ {$customer->name}: {$newMessages} yeni mesaj");
        }

        return $newMessages > 0;
    }
}
