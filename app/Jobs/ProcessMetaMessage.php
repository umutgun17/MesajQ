<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\MetaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessMetaMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public array $data,
        public string $object,
    ) {}

    public function handle(MetaService $metaService): void
    {
        $pageId   = $this->data['page_id'];
        $senderId = $this->data['sender_id'];
        $isEcho   = !empty($this->data['is_echo']);

        // Determine channel type based on object
        $channelType = $this->object === 'instagram'
            ? 'instagram-message'
            : 'facebook-message';

        // Find channel by page_id in settings (bypass tenant scope).
        // orderBy(id) determinizm için; aynı page_id birden çok tenant'ın
        // kanalında tanımlıysa mesaj yanlış firmaya düşebilir → hata logla.
        $matches = Channel::withoutGlobalScopes()
            ->where('type', $channelType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(function ($ch) use ($pageId) {
                $settings = $ch->settings ?? [];
                return ($settings['page_id'] ?? null) === $pageId;
            });

        if ($matches->count() > 1 && $matches->pluck('tenant_id')->unique()->count() > 1) {
            Log::error('ProcessMetaMessage: page_id birden fazla tenant kanalıyla eşleşti — çakışma!', [
                'page_id' => $pageId,
                'channels' => $matches->map(fn ($c) => ['id' => $c->id, 'tenant_id' => $c->tenant_id])->values()->all(),
            ]);
        }

        $channel = $matches->first();

        if (! $channel) {
            Log::warning('ProcessMetaMessage: No channel found', [
                'page_id'      => $pageId,
                'channel_type' => $channelType,
            ]);
            return;
        }

        $tenantId = $channel->tenant_id;
        $credentials = $channel->credentials ?? [];
        $pageAccessToken = $metaService->getPageAccessToken($credentials);

        // Echo messages: sender=page, recipient=customer → flip IDs
        $customerId = $isEcho ? ($this->data['recipient_id'] ?? $senderId) : $senderId;

        // Find or create customer
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'     => $tenantId,
                'platform_id'   => $customerId,
                'platform_type' => $this->object === 'instagram' ? 'instagram' : 'facebook',
            ],
            [
                'name' => 'Meta User',
            ]
        );

        // Fetch profile if customer is new or has a generic name
        $needsProfile = $customer->wasRecentlyCreated
            || in_array($customer->name, ['Meta User', 'Instagram User']);

        if ($needsProfile && $pageAccessToken && !$isEcho) {
            try {
                if ($this->object === 'instagram') {
                    $profile = $metaService->getInstagramUserProfile($customerId, $pageId, $pageAccessToken);
                } else {
                    $profile = $metaService->getUserProfile($customerId, $pageAccessToken);
                }

                if (! in_array($profile['name'], ['Meta User', 'Instagram User'])) {
                    $updateData = [
                        'name'   => $profile['name'],
                        'avatar' => $profile['avatar'],
                    ];

                    // Store Instagram username in metadata
                    if (! empty($profile['username'])) {
                        $metadata = $customer->metadata ?? [];
                        $metadata['instagram_username'] = $profile['username'];
                        $updateData['metadata'] = $metadata;
                    }

                    $customer->update($updateData);
                }
            } catch (\Throwable $e) {
                // Profile fetch failed (API slow/down) — don't fail the whole job.
                // Message will be saved with the generic name; profile can sync later.
                Log::warning('ProcessMetaMessage: Profile fetch failed, continuing', [
                    'customer_id' => $customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Find or create conversation (always keyed by customer_id + page_id)
        $conversationKey = $customerId . '_' . $pageId;
        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'                => $tenantId,
                'channel_id'               => $channel->id,
                'customer_id'              => $customer->id,
                'platform_conversation_id' => $conversationKey,
            ],
            [
                'type'   => 'message',
                'status' => 'open',
            ]
        );

        // Check for duplicate message
        $existingMessage = Message::withoutGlobalScopes()
            ->where('platform_message_id', $this->data['message_id'])
            ->where('conversation_id', $conversation->id)
            ->exists();

        if ($existingMessage) {
            return;
        }

        // Resolve Instagram post permalinks for share/ig_post attachments
        $attachments = $this->data['attachments'] ?? [];
        if (!empty($attachments) && $pageAccessToken) {
            foreach ($attachments as &$att) {
                if (in_array($att['type'] ?? '', ['share', 'ig_post'])) {
                    $igMediaId = $att['payload']['ig_post_media_id'] ?? null;
                    if ($igMediaId && empty($att['payload']['permalink'])) {
                        try {
                            // Try permalink first, fallback to shortcode (for carousel children)
                            $response = Http::timeout(10)
                                ->withOptions(['force_ip_resolve' => 'v4'])
                                ->get("https://graph.facebook.com/v21.0/{$igMediaId}", [
                                    'fields' => 'permalink,shortcode',
                                    'access_token' => $pageAccessToken,
                                ]);
                            if ($response->ok()) {
                                $permalink = $response->json('permalink');
                                if (!$permalink && $response->json('shortcode')) {
                                    $permalink = 'https://www.instagram.com/p/' . $response->json('shortcode') . '/';
                                }
                                if ($permalink) {
                                    $att['payload']['permalink'] = $permalink;
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::warning('ProcessMetaMessage: Failed to resolve IG post permalink', [
                                'media_id' => $igMediaId,
                                'error'    => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
            unset($att);
        }

        // Webhook sometimes omits attachments (e.g. while thread control is held by
        // another app after a Business Suite reply). If both text and attachments are
        // empty the message would render blank — recover attachments via Graph API.
        if (empty($attachments) && $pageAccessToken && !empty($this->data['message_id'])
            && trim((string) ($this->data['text'] ?? '')) === '') {
            $attachments = $this->fetchAttachmentsViaGraph($this->data['message_id'], $pageAccessToken);
        }

        // Create message
        Message::create([
            'tenant_id'           => $tenantId,
            'conversation_id'     => $conversation->id,
            'sender_type'         => $isEcho ? 'agent' : 'customer',
            'sender_id'           => null,
            'content'             => is_string($this->data['text']) ? $this->data['text'] : '',
            'platform_message_id' => $this->data['message_id'],
            'metadata'            => ! empty($attachments)
                ? ['attachments' => $attachments]
                : null,
        ]);

        // Update conversation
        if ($isEcho) {
            // Page's own reply (Business Suite / Instagram app) — mark as resolved
            $conversation->update([
                'last_message_at' => now(),
                'status'          => 'resolved',
            ]);
        } else {
            $conversation->update([
                'last_message_at' => now(),
                'is_read'         => false,
                'unread_count'    => $conversation->unread_count + 1,
                'status'          => 'open',
            ]);
        }

        Log::info('ProcessMetaMessage: Message saved', [
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
            'message_id'      => $this->data['message_id'],
            'is_echo'         => $isEcho,
        ]);

        // Pre-generate AI suggestion for incoming customer messages
        if (!$isEcho) {
            // Clear old suggestion and dispatch new one
            $conversation->update(['ai_suggestion' => null, 'ai_suggestion_confidence' => null]);
            GenerateAiSuggestion::dispatch($conversation->id, $tenantId)
                ->delay(now()->addSeconds(5));

            $tenant = Tenant::find($tenantId);
            $settings = $tenant?->settings ?? [];

            if (($settings['auto_reply'] ?? 'disabled') === 'auto') {
                $delayMin = max(1, (int) ($settings['auto_reply_delay_min'] ?? 3));
                $delayMax = max($delayMin, (int) ($settings['auto_reply_delay_max'] ?? 10));
                $delayMinutes = rand($delayMin, $delayMax);

                SendAutoReply::dispatch($conversation->id, $tenantId)
                    ->delay(now()->addMinutes($delayMinutes));

                Log::info('ProcessMetaMessage: Auto-reply scheduled', [
                    'conversation_id' => $conversation->id,
                    'delay_minutes'   => $delayMinutes,
                    'delay_range'     => "{$delayMin}-{$delayMax}",
                ]);
            }
        }
    }

    /**
     * Fetch a message's attachments from Graph API and normalize them
     * to the webhook attachment shape ({type, payload.url}).
     */
    private function fetchAttachmentsViaGraph(string $messageId, string $pageAccessToken): array
    {
        try {
            $response = Http::timeout(10)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withToken($pageAccessToken)
                ->get("https://graph.facebook.com/v21.0/{$messageId}", [
                    'fields' => 'attachments{mime_type,name,image_data,file_url,video_data}',
                ]);

            if (! $response->ok()) {
                return [];
            }

            $attachments = [];
            foreach ($response->json('attachments.data') ?? [] as $att) {
                if (! empty($att['image_data']['url'])) {
                    $attachments[] = ['type' => 'image', 'payload' => ['url' => $att['image_data']['url']]];
                } elseif (! empty($att['video_data']['url'])) {
                    $attachments[] = ['type' => 'video', 'payload' => ['url' => $att['video_data']['url']]];
                } elseif (! empty($att['file_url'])) {
                    $attachments[] = ['type' => 'file', 'payload' => ['url' => $att['file_url'], 'name' => $att['name'] ?? null]];
                }
            }

            if (! empty($attachments)) {
                Log::info('ProcessMetaMessage: Attachments recovered via Graph API', [
                    'message_id' => $messageId,
                    'count'      => count($attachments),
                ]);
            }

            return $attachments;
        } catch (\Throwable $e) {
            Log::warning('ProcessMetaMessage: Graph attachment fetch failed', [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
            return [];
        }
    }
}
