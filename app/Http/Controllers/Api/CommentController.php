<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiResponseLog;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Conversation;
use App\Services\MetaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommentController extends Controller
{
    public function __construct(
        protected MetaService $metaService,
    ) {}

    public function index(Conversation $conversation): JsonResponse
    {
        $comments = $conversation->comments()
            ->with('replies.user:id,name,avatar')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($comments);
    }

    public function reply(Request $request, Comment $comment): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:2000',
            'is_ai_generated' => 'sometimes|boolean',
            'ai_original_content' => 'sometimes|nullable|string',
        ]);

        $reply = $comment->replies()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'is_ai_generated' => $validated['is_ai_generated'] ?? false,
            'ai_original_content' => $validated['ai_original_content'] ?? null,
        ]);

        // 8 Eki 2026: durum, cevap platforma gerçekten gittikten sonra 'replied' olur (aşağıda).

        // Update AiResponseLog if this was an AI-generated reply
        if ($validated['is_ai_generated'] ?? false) {
            try {
                $log = AiResponseLog::where('comment_id', $comment->id)
                    ->orderByDesc('created_at')
                    ->first();

                if ($log) {
                    $wasEdited = ! empty($validated['ai_original_content'])
                        && $validated['ai_original_content'] !== $validated['content'];

                    $log->update([
                        'was_approved' => true,
                        'was_edited' => $wasEdited,
                        'final_response' => $validated['content'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('CommentController::updateAiResponseLog failed', ['error' => $e->getMessage()]);
            }
        }

        // Send reply to Meta if channel is facebook/instagram comment
        $conversation = $comment->conversation;
        $channelType = $conversation->channel->type ?? null;

        $sendError = null;
        if (in_array($channelType, ['instagram-comment', 'facebook-comment'])
            && $comment->platform_comment_id
        ) {
            $sendError = $this->sendReplyToMeta($conversation, $comment, $reply, $validated['content']);
        }

        // Gönderim başarısızsa yorum 'pending' kalır: personel hatayı görür ve
        // tekrar deneyebilir (Tem–Eki 2026'da 29 FB cevabı sessizce 'replied' olmuştu).
        if (! $sendError) {
            $comment->update(['status' => 'replied']);
        }

        $reply->load('user:id,name,avatar');

        $response = $reply->toArray();
        if ($sendError) {
            $response['send_error'] = $sendError;
            $response['comment_status'] = $comment->fresh()->status;
        }

        return response()->json($response, 201);
    }

    public function updateStatus(Request $request, Comment $comment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,replied,hidden',
        ]);

        $comment->update($validated);

        return response()->json($comment);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $conversation = $comment->conversation;
        $channel = $conversation?->channel;
        $channelType = $channel?->type;

        // Delete from Meta if platform comment
        $metaError = null;
        if (in_array($channelType, ['instagram-comment', 'facebook-comment'])
            && $comment->platform_comment_id
            && $channel
        ) {
            $credentials = $channel->credentials ?? [];
            $pageAccessToken = $this->metaService->getPageAccessToken($credentials);

            if ($pageAccessToken) {
                $result = $this->metaService->deleteComment(
                    $comment->platform_comment_id,
                    $pageAccessToken
                );

                if (! $result['success']) {
                    $errorMsg = $result['error']['error']['message'] ?? 'Bilinmeyen hata';
                    $metaError = "Yorum Meta'dan silinemedi: {$errorMsg}";
                }
            }
        }

        if ($metaError) {
            return response()->json(['message' => $metaError], 422);
        }

        // Delete replies and comment from DB
        $comment->replies()->delete();
        $comment->delete();

        // If no comments left, delete conversation too
        $remainingComments = Comment::where('conversation_id', $conversation->id)->count();
        if ($remainingComments === 0) {
            $conversation->delete();
        }

        return response()->json(['message' => 'Yorum silindi.']);
    }

    protected function sendReplyToMeta(
        Conversation $conversation,
        Comment $comment,
        CommentReply $reply,
        string $content,
    ): ?string {
        $channel = $conversation->channel;

        if (! $channel) {
            return null;
        }

        $credentials = $channel->credentials ?? [];
        $pageAccessToken = $this->metaService->getPageAccessToken($credentials);

        if (! $pageAccessToken) {
            Log::warning('CommentController: No page access token for channel', [
                'channel_id' => $channel->id,
            ]);
            return 'Erişim tokeni bulunamadı';
        }

        $result = $this->metaService->replyToComment(
            $comment->platform_comment_id,
            $content,
            $pageAccessToken,
            $channel->type
        );

        if ($result['success'] && isset($result['reply_id'])) {
            $reply->update(['platform_reply_id' => $result['reply_id']]);
            return null;
        }

        $errorMsg = $result['error']['error']['message'] ?? 'Bilinmeyen hata';
        $platformName = $channel->type === 'instagram-comment' ? 'Instagram' : 'Facebook';

        if (str_contains($errorMsg, 'Permission')) {
            $permissionHint = $channel->type === 'instagram-comment'
                ? 'instagram_manage_comments'
                : 'pages_manage_engagement';

            return "{$platformName} yorum yanıtlama izni ({$permissionHint}) bu sayfa için verilmemiş — Ayarlar › Kanallar'dan Meta bağlantısını yenileyin. Yanıt kaydedildi ama {$platformName}'a GÖNDERİLMEDİ.";
        }

        return "Yanıt {$platformName}'a gönderilemedi: {$errorMsg}";
    }
}
