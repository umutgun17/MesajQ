<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class BackfillMetaMessages extends Command
{
    protected $signature = 'meta:backfill-messages
                            {--channel=5 : Channel ID}
                            {--delay=1 : Delay between API calls in seconds}';

    protected $description = 'Tek mesajlı Instagram konuşmalarının eksik mesajlarını Meta API üzerinden doldurur';

    private string $apiUrl = 'https://graph.facebook.com/v21.0';

    public function handle(): int
    {
        $channel = Channel::withoutGlobalScopes()->find($this->option('channel'));
        if (! $channel) {
            $this->error('Kanal bulunamadı.');
            return 1;
        }

        $token = $channel->credentials['page_access_token'] ?? null;
        $pageId = $channel->settings['page_id'] ?? null;

        if (! $token || ! $pageId) {
            $this->error('Token veya page_id eksik.');
            return 1;
        }

        $delay = max(1, (int) $this->option('delay'));

        // Find conversations with only 1 message
        $conversations = Conversation::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->withCount('messages')
            ->with('customer')
            ->get()
            ->where('messages_count', 1);

        $this->info("Tek mesajlı konuşma sayısı: {$conversations->count()}");

        $filled = 0;
        $skipped = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($conversations->count());
        $bar->start();

        foreach ($conversations as $conv) {
            $customerId = $conv->customer->platform_id ?? null;
            if (! $customerId) {
                $skipped++;
                $bar->advance();
                continue;
            }

            try {
                $response = Http::withToken($token)
                    ->timeout(10)
                    ->get("{$this->apiUrl}/me/conversations", [
                        'fields' => 'id,participants,messages.limit(10){message,from,created_time,id}',
                        'platform' => str_contains($channel->type, 'instagram') ? 'instagram' : null,
                        'user_id' => $customerId,
                        'limit' => 1,
                    ]);

                if ($response->failed()) {
                    $failed++;
                    $bar->advance();
                    usleep($delay * 500000);
                    continue;
                }

                $apiConv = $response->json('data.0');
                $messages = $apiConv['messages']['data'] ?? [];
                $newCount = 0;

                // Extract username from participants
                $participants = $apiConv['participants']['data'] ?? [];
                foreach ($participants as $p) {
                    if ($p['id'] !== $pageId && ! empty($p['username'])) {
                        $metadata = $conv->customer->metadata ?? [];
                        if (empty($metadata['instagram_username'])) {
                            $metadata['instagram_username'] = $p['username'];
                            $conv->customer->update(['metadata' => $metadata]);
                        }
                        break;
                    }
                }

                foreach (array_reverse($messages) as $msg) {
                    $mid = $msg['id'] ?? null;
                    if (! $mid) continue;

                    if (Message::withoutGlobalScopes()
                        ->where('platform_message_id', $mid)
                        ->where('conversation_id', $conv->id)
                        ->exists()) {
                        continue;
                    }

                    $isFromPage = ($msg['from']['id'] ?? '') === $pageId;
                    $createdTime = isset($msg['created_time'])
                        ? \Carbon\Carbon::parse($msg['created_time'])
                        : now();

                    $message = new Message([
                        'tenant_id' => $channel->tenant_id,
                        'conversation_id' => $conv->id,
                        'sender_type' => $isFromPage ? 'agent' : 'customer',
                        'sender_id' => null,
                        'content' => $msg['message'] ?? '',
                        'platform_message_id' => $mid,
                    ]);
                    $message->created_at = $createdTime;
                    $message->save();
                    $newCount++;
                }

                if ($newCount > 0) {
                    // Update conversation based on last message
                    $lastMsg = collect($messages)->first();
                    $lastIsFromPage = ($lastMsg['from']['id'] ?? '') === $pageId;
                    $conv->update([
                        'last_message_at' => $lastMsg['created_time'] ?? now(),
                        'status' => $lastIsFromPage ? 'resolved' : 'open',
                    ]);
                    $filled++;
                } else {
                    $skipped++;
                }

            } catch (\Throwable $e) {
                $failed++;
            }

            $bar->advance();
            usleep($delay * 500000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Tamamlandı: {$filled} dolduruldu, {$skipped} atlandı, {$failed} hata.");

        return 0;
    }
}
