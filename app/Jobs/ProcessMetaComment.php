<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\MetaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMetaComment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public array $data,
        public string $object,
    ) {}

    public function handle(MetaService $metaService): void
    {
        // Only process new comments
        if (($this->data['verb'] ?? 'add') !== 'add') {
            return;
        }

        $pageId = $this->data['page_id'];

        // Determine channel type
        $channelType = $this->object === 'instagram'
            ? 'instagram-comment'
            : 'facebook-comment';

        // Find channel by page_id in settings (bypass tenant scope).
        // orderBy(id) determinizm için; çok-tenant çakışması hata loglanır.
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
            Log::error('ProcessMetaComment: page_id birden fazla tenant kanalıyla eşleşti — çakışma!', [
                'page_id' => $pageId,
                'channels' => $matches->map(fn ($c) => ['id' => $c->id, 'tenant_id' => $c->tenant_id])->values()->all(),
            ]);
        }

        $channel = $matches->first();

        if (! $channel) {
            Log::warning('ProcessMetaComment: No channel found', [
                'page_id'      => $pageId,
                'channel_type' => $channelType,
            ]);
            return;
        }

        $tenantId = $channel->tenant_id;

        // ── Page's own reply (via Business Suite) → save as CommentReply ──
        if (!empty($this->data['is_page_reply']) && !empty($this->data['parent_id'])) {
            $this->handlePageReply($tenantId, $channel);
            return;
        }

        // ── Normal user comment ──
        $from = $this->data['from'] ?? [];
        $fromId = $from['id'] ?? null;
        $fromName = $from['name'] ?? $from['username'] ?? null;

        // If no from.id (missing pages_read_user_content / instagram_manage_comments permission),
        // use comment_id as a unique anonymous identity so the comment still gets saved
        if (! $fromId) {
            $fromId = 'anon_' . $this->data['comment_id'];
        }

        // Always guarantee a non-null name — customers.name has a NOT NULL constraint.
        // Facebook sometimes omits from.name (privacy settings, business accounts, etc.)
        // which caused a DB error and silently dropped the comment after 3 retries.
        $fromName = $fromName ?? ($this->object === 'instagram' ? 'Instagram Kullanıcısı' : 'Facebook Kullanıcısı');

        // Find or create customer
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'     => $tenantId,
                'platform_id'   => $fromId,
                'platform_type' => $this->object === 'instagram' ? 'instagram' : 'facebook',
            ],
            [
                'name' => $fromName,
            ]
        );

        // Find or create conversation for this post+user pair
        $postId = $this->data['post_id'];

        // Check if another user's conversation already has post metadata (avoid re-fetching)
        $existingConv = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel_id', $channel->id)
            ->where('platform_conversation_id', 'like', 'post_' . $postId . '_%')
            ->whereNotNull('metadata')
            ->first();

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'                => $tenantId,
                'channel_id'               => $channel->id,
                'platform_conversation_id' => 'post_' . $postId . '_user_' . $fromId,
            ],
            [
                'customer_id' => $customer->id,
                'type'        => 'comment',
                'status'      => 'open',
                'metadata'    => $existingConv?->metadata,
            ]
        );

        // Fetch post details if not cached in conversation metadata
        $postPermalink = $this->data['post_url'];
        $metadata = $conversation->metadata ?? [];

        if (empty($metadata['post']) && $postId) {
            $token = $metaService->getPageAccessToken($channel->credentials ?? []);

            $postDetails = null;
            if ($token) {
                $postDetails = $channelType === 'instagram-comment'
                    ? $metaService->getInstagramPostDetails($postId, $token)
                    : $metaService->getFacebookPostDetails($postId, $token);
            }

            if ($postDetails) {
                $metadata['post'] = $postDetails;
                $postPermalink = $postDetails['permalink'] ?? $postPermalink;
            } else {
                // Fallback: create minimal metadata from webhook data so post preview card is shown
                $metadata['post'] = [
                    'permalink'      => $postPermalink,
                    'caption'        => null,
                    'thumbnail_url'  => null,
                    'media_type'     => null,
                    'like_count'     => 0,
                    'comments_count' => 0,
                    'fetched_at'     => now()->toIso8601String(),
                ];
            }

            $conversation->update(['metadata' => $metadata]);
        } elseif (!empty($metadata['post']['permalink'])) {
            $postPermalink = $metadata['post']['permalink'];
        }

        // Check for duplicate comment
        $existingComment = Comment::withoutGlobalScopes()
            ->where('platform_comment_id', $this->data['comment_id'])
            ->where('conversation_id', $conversation->id)
            ->exists();

        if ($existingComment) {
            return;
        }

        // Create comment
        Comment::create([
            'tenant_id'         => $tenantId,
            'conversation_id'   => $conversation->id,
            'platform_comment_id' => $this->data['comment_id'],
            'platform_post_id'  => $postId,
            'post_url'          => $postPermalink,
            'content'           => $this->data['message'],
            'status'            => 'pending',
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => now(),
            'is_read'         => false,
            'unread_count'    => $conversation->unread_count + 1,
            'status'          => 'open',
        ]);

        Log::info('ProcessMetaComment: Comment saved', [
            'conversation_id' => $conversation->id,
            'comment_id'      => $this->data['comment_id'],
            'post_id'         => $postId,
        ]);
    }

    /**
     * Handle a reply made by the page itself (via Business Suite).
     * Finds the parent comment by platform_comment_id and saves as CommentReply.
     */
    protected function handlePageReply(int $tenantId, Channel $channel): void
    {
        $parentId = $this->data['parent_id'];
        $replyPlatformId = $this->data['comment_id'];
        $message = $this->data['message'] ?? '';

        // Check for duplicate
        $exists = CommentReply::withoutGlobalScopes()
            ->where('platform_reply_id', $replyPlatformId)
            ->exists();

        if ($exists) {
            return;
        }

        // Find the parent comment
        $parentComment = Comment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('platform_comment_id', $parentId)
            ->first();

        if (! $parentComment) {
            Log::info('ProcessMetaComment: Page reply — parent comment not found, skipping', [
                'parent_id' => $parentId,
                'reply_id'  => $replyPlatformId,
            ]);
            return;
        }

        // Create reply (no user_id — came from Business Suite, not our app)
        CommentReply::create([
            'tenant_id'        => $tenantId,
            'comment_id'       => $parentComment->id,
            'user_id'          => null,
            'content'          => $message,
            'is_ai_generated'  => false,
            'platform_reply_id' => $replyPlatformId,
        ]);

        // Mark parent comment as replied
        $parentComment->update(['status' => 'replied']);

        Log::info('ProcessMetaComment: Business Suite reply saved', [
            'parent_comment_id' => $parentComment->id,
            'platform_reply_id' => $replyPlatformId,
        ]);
    }
}
