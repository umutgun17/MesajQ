<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaService
{
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = config('meta.graph_api_url');
    }

    /**
     * Tüm aktif tenant'ların ayarındaki + .env'deki aday değerleri döndürür.
     * Eski hali yalnız "ilk aktif tenant"a bakıyordu — 2+ tenant'ta hangi
     * secret'ın seçileceği belirsizdi ve diğer tenant'ların webhook'ları
     * doğrulanamıyordu.
     *
     * @return array<int,string>
     */
    protected function candidateSecrets(string $settingsKey, string $configKey): array
    {
        $values = Tenant::where('is_active', true)
            ->get()
            ->map(fn (Tenant $t) => (string) (($t->settings ?? [])[$settingsKey] ?? ''))
            ->filter()
            ->all();

        $env = (string) config($configKey, '');
        if ($env !== '') {
            $values[] = $env;
        }

        return array_values(array_unique($values));
    }

    /**
     * Webhook verification — Meta sends GET with hub.mode, hub.verify_token, hub.challenge
     */
    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): string|false
    {
        if ($mode !== 'subscribe' || $token === null || $token === '') {
            return false;
        }

        foreach ($this->candidateSecrets('meta_verify_token', 'meta.verify_token') as $candidate) {
            if (hash_equals($candidate, $token)) {
                return $challenge ?? '';
            }
        }

        return false;
    }

    /**
     * Validate X-Hub-Signature-256 header — herhangi bir aktif tenant'ın
     * app secret'ı (veya .env) ile eşleşirse geçerli sayılır.
     */
    public function validateSignature(string $payload, string $signature): bool
    {
        foreach ($this->candidateSecrets('meta_app_secret', 'meta.app_secret') as $secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse messaging event (Messenger + Instagram DM)
     * Returns normalized array or null
     */
    public function parseMessagingEvent(array $entry): ?array
    {
        $messaging = $entry['messaging'][0] ?? null;

        if (! $messaging || ! isset($messaging['message'])) {
            return null;
        }

        $senderId = $messaging['sender']['id'] ?? null;
        $recipientId = $messaging['recipient']['id'] ?? null;
        $message = $messaging['message'];

        if (! $senderId || ! $recipientId) {
            return null;
        }

        // Echo messages = sent by the page itself (Business Suite, our app, etc.)
        $isEcho = !empty($message['is_echo']);

        return [
            'page_id'         => $entry['id'],
            'sender_id'       => $senderId,
            'recipient_id'    => $recipientId,
            'message_id'      => $message['mid'] ?? null,
            'text'            => $message['text'] ?? '',
            'attachments'     => $message['attachments'] ?? [],
            'timestamp'       => $messaging['timestamp'] ?? now()->timestamp,
            'is_echo'         => $isEcho,
        ];
    }

    /**
     * Parse comment event (Facebook feed changes + Instagram comments)
     * Returns normalized array or null
     */
    public function parseCommentEvent(array $entry): ?array
    {
        $change = $entry['changes'][0] ?? null;

        if (! $change) {
            return null;
        }

        $field = $change['field'] ?? null;
        $value = $change['value'] ?? [];

        // Facebook page feed comments (organic + ad posts)
        if ($field === 'feed') {
            return $this->parseFacebookComment($entry, $value);
        }

        // Instagram comments
        if ($field === 'comments') {
            return $this->parseInstagramComment($entry, $value);
        }

        return null;
    }

    /**
     * Parse Facebook page feed comment
     */
    protected function parseFacebookComment(array $entry, array $value): ?array
    {
        $item = $value['item'] ?? null;

        if ($item !== 'comment') {
            return null;
        }

        $isOwnComment = isset($value['from']['id']) && $value['from']['id'] === ($entry['id'] ?? null);

        // Skip own top-level comments (not replies)
        if ($isOwnComment && empty($value['parent_id'])) {
            return null;
        }

        return [
            'page_id'      => $entry['id'],
            'comment_id'   => $value['comment_id'] ?? null,
            'post_id'      => $value['post_id'] ?? null,
            'parent_id'    => $value['parent_id'] ?? null,
            'from'         => $value['from'] ?? [],
            'message'      => $value['message'] ?? '',
            'created_time' => $value['created_time'] ?? null,
            'verb'         => $value['verb'] ?? 'add',
            'post_url'     => isset($value['post_id'])
                ? "https://www.facebook.com/{$value['post_id']}"
                : null,
            'is_page_reply' => $isOwnComment,
        ];
    }

    /**
     * Parse Instagram comment webhook
     *
     * Instagram comment webhook format:
     * { "field": "comments", "value": { "id": "comment_id", "text": "...",
     *   "from": { "id": "user_id", "username": "..." },
     *   "media": { "id": "media_id", "media_product_type": "FEED|REELS|AD" } } }
     */
    protected function parseInstagramComment(array $entry, array $value): ?array
    {
        $commentId = $value['id'] ?? null;
        $text = $value['text'] ?? '';

        if (! $commentId || ! $text) {
            return null;
        }

        $isOwnComment = isset($value['from']['id']) && $value['from']['id'] === ($entry['id'] ?? null);

        // Skip own top-level comments (not replies)
        if ($isOwnComment && empty($value['parent_id'])) {
            return null;
        }

        $media = $value['media'] ?? [];
        $mediaId = $media['id'] ?? null;

        return [
            'page_id'      => $entry['id'],
            'comment_id'   => $commentId,
            'post_id'      => $mediaId,
            'parent_id'    => $value['parent_id'] ?? null,
            'from'         => $value['from'] ?? [],
            'message'      => $text,
            'created_time' => $value['timestamp'] ?? null,
            'verb'         => 'add',
            'post_url'     => null, // Will be set from Graph API permalink
            'is_page_reply' => $isOwnComment,
        ];
    }

    /**
     * Send message via Messenger / Instagram DM
     *
     * Tries RESPONSE type first (standard 24h window).
     * Falls back to MESSAGE_TAG + HUMAN_AGENT (7-day window).
     *
     * @param string      $recipientId     Platform user ID
     * @param string      $text            Message content
     * @param string      $pageAccessToken Page access token
     * @param string      $channelType     'facebook-message' or 'instagram-message'
     * @param string|null $igAccountId     IG Business Account ID (required for instagram-message)
     */
    public function sendMessage(string $recipientId, string $text, string $pageAccessToken, string $channelType = 'facebook-message', ?string $igAccountId = null): array
    {
        // Both Messenger and Instagram DM use /me/messages with page token
        $endpoint = "{$this->apiUrl}/me/messages";

        // 1) Try standard RESPONSE type (within 24h messaging window)
        $response = Http::withToken($pageAccessToken)
            ->post($endpoint, [
                'recipient' => ['id' => $recipientId],
                'message'   => ['text' => $text],
                'messaging_type' => 'RESPONSE',
            ]);

        if ($response->successful()) {
            return [
                'success'    => true,
                'message_id' => $response->json('message_id'),
            ];
        }

        // 2) If outside 24h window, try HUMAN_AGENT tag (7-day window)
        // Error codes: 10 = outside messaging window (Messenger), 3 = capability error (Instagram)
        $errorCode = $response->json('error.code');
        if (in_array($errorCode, [3, 10], true) || $response->status() === 400) {
            Log::info('MetaService::sendMessage RESPONSE failed, trying HUMAN_AGENT tag', [
                'recipient_id' => $recipientId,
                'channel_type' => $channelType,
                'error_code'   => $errorCode,
            ]);

            $tagResponse = Http::withToken($pageAccessToken)
                ->post($endpoint, [
                    'recipient' => ['id' => $recipientId],
                    'message'   => ['text' => $text],
                    'messaging_type' => 'MESSAGE_TAG',
                    'tag' => 'HUMAN_AGENT',
                ]);

            if ($tagResponse->successful()) {
                return [
                    'success'    => true,
                    'message_id' => $tagResponse->json('message_id'),
                    'used_human_agent' => true,
                ];
            }

            Log::error('MetaService::sendMessage failed (both RESPONSE and HUMAN_AGENT)', [
                'recipient_id'   => $recipientId,
                'channel_type'   => $channelType,
                'response_error' => $response->json(),
                'tag_error'      => $tagResponse->json(),
            ]);

            return ['success' => false, 'error' => $tagResponse->json()];
        }

        Log::error('MetaService::sendMessage failed', [
            'recipient_id' => $recipientId,
            'channel_type' => $channelType,
            'status'       => $response->status(),
            'body'         => $response->json(),
        ]);

        return ['success' => false, 'error' => $response->json()];
    }

    /**
     * 8 Eki 2026: Yorumdan doğan Messenger sohbetine "özel yanıt" (Private Reply).
     *
     * Kullanıcı sayfaya hiç mesaj yazmadan yalnız yorum yaptıysa Facebook,
     * Messenger'da "sen bir konuşma başlatana kadar görmeyecek, 7 günün var"
     * diye bir yer tutucu sohbet açar. Bu kişiye recipient.id (PSID) ile
     * /me/messages çağrısı #551 "person isn't available" döner; tek yol
     * recipient.comment_id ile göndermektir (eski karşılığı
     * POST /{comment_id}/private_replies). Kurallar: yorum başına TEK özel
     * yanıt, yorumdan itibaren 7 gün; kullanıcı cevap yazınca normal 24s
     * pencere açılır.
     */
    public function sendPrivateReply(string $commentId, string $text, string $pageAccessToken): array
    {
        $response = Http::withToken($pageAccessToken)
            ->post("{$this->apiUrl}/me/messages", [
                'recipient' => ['comment_id' => $commentId],
                'message'   => ['text' => $text],
            ]);

        if ($response->successful() && $response->json('message_id')) {
            return ['success' => true, 'message_id' => $response->json('message_id'), 'via' => 'send_api'];
        }

        $first = $response->json();

        // Eski uç — bazı sayfa/uygulama kombinasyonlarında yalnız bu çalışır.
        $legacy = Http::withToken($pageAccessToken)
            ->post("{$this->apiUrl}/{$commentId}/private_replies", [
                'message' => $text,
            ]);

        if ($legacy->successful() && $legacy->json('id')) {
            return ['success' => true, 'message_id' => $legacy->json('id'), 'via' => 'private_replies'];
        }

        Log::error('MetaService::sendPrivateReply failed', [
            'comment_id'    => $commentId,
            'send_api'      => $first,
            'private_reply' => $legacy->json(),
        ]);

        return ['success' => false, 'error' => $legacy->json() ?: $first];
    }

    /**
     * Yorumdan doğan Messenger sohbeti için henüz özel yanıt kullanılmamış
     * yorumun platform_comment_id'sini bulur ({post_id}_{comment_id}).
     *
     * 1) Facebook'un yer tutucu mesajındaki "comment_id=…" bağlantısı,
     * 2) yoksa aynı müşterinin (platform_id) son 7 gündeki Facebook yorumları.
     */
    public function resolveCommentOrigin(\App\Models\Conversation $conversation): ?string
    {
        $customer = $conversation->customer;
        if (! $customer?->platform_id) {
            return null;
        }

        $used = \App\Models\Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'agent')
            ->whereNotNull('platform_message_id')
            ->get()
            ->map(fn ($m) => ($m->metadata ?? [])['private_reply_comment_id'] ?? null)
            ->filter()
            ->values()
            ->all();

        $candidates = [];

        $placeholder = \App\Models\Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'customer')
            ->where('content', 'like', '%comment_id=%')
            ->orderBy('id')
            ->get();

        foreach ($placeholder as $m) {
            if (preg_match_all('/comment_id=(\d+)/', (string) $m->content, $mm)) {
                foreach ($mm[1] as $cid) {
                    $candidates[] = $cid;
                }
            }
        }

        $commentIds = \App\Models\Customer::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('platform_id', $customer->platform_id)
            ->pluck('id');

        $recent = \App\Models\Comment::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes()->whereIn('customer_id', $commentIds))
            ->orderByDesc('id')
            ->get(['platform_comment_id']);

        // Yer tutucudaki id'yi tam platform id'ye ({post}_{comment}) çevir; sonra
        // müşterinin diğer son yorumları (her biri ayrı özel yanıt hakkı).
        $ordered = [];
        foreach ($candidates as $cid) {
            foreach ($recent as $c) {
                if (str_ends_with((string) $c->platform_comment_id, '_' . $cid) || $c->platform_comment_id === $cid) {
                    $ordered[] = $c->platform_comment_id;
                }
            }
        }
        foreach ($recent as $c) {
            $ordered[] = $c->platform_comment_id;
        }

        foreach (array_unique($ordered) as $pid) {
            if ($pid && ! in_array($pid, $used, true)) {
                return $pid;
            }
        }

        return null;
    }

    /**
     * Reply to a comment (Facebook: /comments, Instagram: /replies)
     */
    public function replyToComment(string $commentId, string $text, string $pageAccessToken, string $channelType = 'facebook-comment'): array
    {
        $endpoint = $channelType === 'instagram-comment'
            ? "{$this->apiUrl}/{$commentId}/replies"
            : "{$this->apiUrl}/{$commentId}/comments";

        $response = Http::withToken($pageAccessToken)
            ->post($endpoint, [
                'message' => $text,
            ]);

        if ($response->failed()) {
            Log::error('MetaService::replyToComment failed', [
                'comment_id' => $commentId,
                'status'     => $response->status(),
                'body'       => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        return [
            'success'  => true,
            'reply_id' => $response->json('id'),
        ];
    }

    /**
     * Get user profile from Meta Graph API (Facebook Messenger)
     */
    public function getUserProfile(string $userId, string $pageAccessToken): array
    {
        $response = Http::withToken($pageAccessToken)
            ->get("{$this->apiUrl}/{$userId}", [
                'fields' => 'name,profile_pic',
            ]);

        if ($response->failed()) {
            Log::warning('MetaService::getUserProfile failed', [
                'user_id' => $userId,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);

            return [
                'name'   => 'Meta User',
                'avatar' => null,
            ];
        }

        return [
            'name'   => $response->json('name', 'Meta User'),
            'avatar' => $response->json('profile_pic'),
        ];
    }

    /**
     * Get Instagram user profile — tries direct API first (works with Advanced Access),
     * then falls back to conversations API participant data.
     */
    public function getInstagramUserProfile(string $igScopedId, string $igBusinessAccountId, string $pageAccessToken): array
    {
        // 1) Try direct profile endpoint (works after App Review / Advanced Access)
        $response = Http::withToken($pageAccessToken)
            ->get("{$this->apiUrl}/{$igScopedId}", [
                'fields' => 'name,profile_pic,username',
            ]);

        if ($response->successful()) {
            $name = $response->json('name')
                ?? $response->json('username')
                ?? 'Instagram User';

            return [
                'name'     => $name,
                'avatar'   => $response->json('profile_pic'),
                'username' => $response->json('username'),
            ];
        }

        // 2) Fallback: fetch recent conversations and find participant name
        $convResponse = Http::withToken($pageAccessToken)
            ->timeout(10)
            ->get("{$this->apiUrl}/{$igBusinessAccountId}/conversations", [
                'fields' => 'participants',
                'limit'  => 10,
            ]);

        if ($convResponse->successful()) {
            foreach ($convResponse->json('data', []) as $conv) {
                foreach ($conv['participants']['data'] ?? [] as $p) {
                    if ($p['id'] === $igScopedId && ! empty($p['name'])) {
                        return [
                            'name'     => $p['name'],
                            'avatar'   => null,
                            'username' => $p['username'] ?? null,
                        ];
                    }
                }
            }
        }

        Log::info('MetaService::getInstagramUserProfile - could not resolve name (Advanced Access required)', [
            'ig_scoped_id' => $igScopedId,
        ]);

        return [
            'name'     => 'Instagram User',
            'avatar'   => null,
            'username' => null,
        ];
    }

    /**
     * Get Instagram post details from Graph API
     */
    public function getInstagramPostDetails(string $mediaId, string $token): ?array
    {
        $response = Http::withToken($token)
            ->timeout(10)
            ->get("{$this->apiUrl}/{$mediaId}", [
                'fields' => 'caption,media_type,media_url,like_count,comments_count,thumbnail_url,permalink,timestamp,children{media_url,media_type,thumbnail_url}',
            ]);

        if ($response->failed()) {
            Log::warning('MetaService::getInstagramPostDetails failed', [
                'media_id' => $mediaId,
                'status'   => $response->status(),
                'body'     => $response->json(),
            ]);
            return null;
        }

        $data = $response->json();

        // Resolve thumbnail: direct fields first, then first child for CAROUSEL_ALBUM
        $thumbnailUrl = $data['thumbnail_url'] ?? $data['media_url'] ?? null;
        if (! $thumbnailUrl && ! empty($data['children']['data'])) {
            $firstChild = $data['children']['data'][0];
            $thumbnailUrl = $firstChild['thumbnail_url'] ?? $firstChild['media_url'] ?? null;
        }

        return [
            'permalink'      => $data['permalink'] ?? null,
            'caption'        => $data['caption'] ?? null,
            'thumbnail_url'  => $thumbnailUrl,
            'media_type'     => $data['media_type'] ?? null,
            'like_count'     => $data['like_count'] ?? 0,
            'comments_count' => $data['comments_count'] ?? 0,
            'fetched_at'     => now()->toIso8601String(),
        ];
    }

    /**
     * Get Facebook post details from Graph API
     */
    public function getFacebookPostDetails(string $postId, string $token): ?array
    {
        $response = Http::withToken($token)
            ->timeout(10)
            ->get("{$this->apiUrl}/{$postId}", [
                'fields' => 'message,full_picture,permalink_url,likes.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
            ]);

        if ($response->failed()) {
            Log::warning('MetaService::getFacebookPostDetails failed', [
                'post_id' => $postId,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);
            return null;
        }

        $data = $response->json();

        return [
            'permalink'      => $data['permalink_url'] ?? null,
            'caption'        => $data['message'] ?? null,
            'thumbnail_url'  => $data['full_picture'] ?? null,
            'media_type'     => null,
            'like_count'     => $data['likes']['summary']['total_count'] ?? 0,
            'comments_count' => $data['comments']['summary']['total_count'] ?? 0,
            'fetched_at'     => now()->toIso8601String(),
        ];
    }

    /**
     * Delete a comment on Facebook or Instagram
     */
    public function deleteComment(string $commentId, string $pageAccessToken): array
    {
        $response = Http::withToken($pageAccessToken)
            ->delete("{$this->apiUrl}/{$commentId}");

        if ($response->failed()) {
            Log::error('MetaService::deleteComment failed', [
                'comment_id' => $commentId,
                'status'     => $response->status(),
                'body'       => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        return ['success' => true];
    }

    /**
     * Get page access token from channel credentials
     */
    public function getPageAccessToken(array $credentials): ?string
    {
        return $credentials['page_access_token']
            ?? $credentials['access_token']
            ?? config('meta.page_access_token');
    }
}
