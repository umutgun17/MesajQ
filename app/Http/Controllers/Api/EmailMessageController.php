<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailMessage::query()
            ->orderByDesc('created_at');

        // Category filter (default: show non-customer_request)
        if ($request->filled('category')) {
            if ($request->category === 'all') {
                // show everything
            } else {
                $query->where('ai_category', $request->category);
            }
        } else {
            // Default: everything except customer_request (those are in conversations)
            $query->where('ai_category', '!=', 'customer_request');
        }

        // Search
        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('from_email', 'ilike', "%{$search}%")
                  ->orWhere('from_name', 'ilike', "%{$search}%")
                  ->orWhere('subject', 'ilike', "%{$search}%");
            });
        }

        $emails = $query->paginate(50);

        return response()->json($emails);
    }

    public function counts(): JsonResponse
    {
        $counts = EmailMessage::query()
            ->selectRaw("ai_category, count(*) as cnt")
            ->groupBy('ai_category')
            ->pluck('cnt', 'ai_category');

        return response()->json([
            'total' => $counts->sum(),
            'customer_request' => $counts->get('customer_request', 0),
            'outgoing_reply' => $counts->get('outgoing_reply', 0),
            'other' => $counts->get('other', 0),
            'notification' => $counts->get('notification', 0),
            'spam' => $counts->get('spam', 0),
            'auto_reply' => $counts->get('auto_reply', 0),
            'filtered' => ($counts->get('other', 0) + $counts->get('notification', 0) + $counts->get('spam', 0) + $counts->get('auto_reply', 0)),
        ]);
    }

    public function reclassify(Request $request, EmailMessage $emailMessage): JsonResponse
    {
        $validated = $request->validate([
            'ai_category' => 'required|in:customer_request,spam,notification,auto_reply,other',
        ]);

        $emailMessage->update(['ai_category' => $validated['ai_category']]);

        // If reclassified to customer_request and no conversation yet, create one
        if ($validated['ai_category'] === 'customer_request' && ! $emailMessage->conversation_id) {
            $channel = $emailMessage->channel;
            $service = new \App\Services\EmailService($channel);

            $conversation = $service->findOrCreateConversation([
                'message_id' => $emailMessage->message_id,
                'in_reply_to' => $emailMessage->in_reply_to,
                'references' => $emailMessage->references,
                'from_email' => $emailMessage->from_email,
                'from_name' => $emailMessage->from_name,
                'subject' => $emailMessage->subject,
            ], $channel);

            \App\Models\Message::create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'content' => $this->buildContent($emailMessage),
                'platform_message_id' => $emailMessage->message_id,
            ]);

            $conversation->update([
                'status' => 'open',
                'is_read' => false,
                'unread_count' => ($conversation->unread_count ?? 0) + 1,
                'last_message_at' => $emailMessage->created_at,
            ]);

            $emailMessage->update(['conversation_id' => $conversation->id]);
        }

        return response()->json(['message' => 'Kategori güncellendi.', 'email_message' => $emailMessage->fresh()]);
    }

    protected function buildContent(EmailMessage $em): string
    {
        $parts = [];
        if (! empty($em->subject)) {
            $parts[] = "Konu: {$em->subject}";
        }
        if (! empty($em->body_text)) {
            $parts[] = $em->body_text;
        }

        return implode("\n\n", $parts) ?: '(İçerik yok)';
    }
}
