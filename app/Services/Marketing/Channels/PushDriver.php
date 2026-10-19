<?php

namespace App\Services\Marketing\Channels;

use App\Models\WebPushSubscription;
use App\Services\Marketing\SendResult;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push kanalı — VAPID ile tarayıcı bildirimleri (minishlink/web-push).
 *
 * `$to` = abonelik endpoint'i. Mesaj: ['subject'=>başlık, 'body'=>metin,
 * 'url'=>tıklama hedefi, 'icon'=>ikon]. 404/410 dönerse abonelik pasiflenir.
 */
class PushDriver implements ChannelDriverInterface
{
    public function key(): string
    {
        return 'push';
    }

    public function isAvailable(int $tenantId): bool
    {
        return ! empty(config('webpush.public_key'))
            && ! empty(config('webpush.private_key'));
    }

    public function send(int $tenantId, string $to, array $message): SendResult
    {
        $sub = WebPushSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('endpoint', $to)
            ->where('is_active', true)
            ->first();

        if (! $sub) {
            return SendResult::fail('Aktif push aboneliği bulunamadı.', 'webpush');
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('webpush.subject'),
                    'publicKey' => config('webpush.public_key'),
                    'privateKey' => config('webpush.private_key'),
                ],
            ]);

            $payload = json_encode([
                'title' => $message['subject'] ?? 'Bildirim',
                'body' => $message['body'] ?? '',
                'url' => $message['url'] ?? '/',
                'icon' => $message['icon'] ?? null,
            ], JSON_UNESCAPED_UNICODE);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                ]),
                $payload,
            );

            if ($report->isSuccess()) {
                $sub->update(['last_sent_at' => now()]);

                return SendResult::ok(provider: 'webpush');
            }

            // Süresi dolmuş / iptal edilmiş abonelik → pasifle
            if ($report->isSubscriptionExpired()) {
                $sub->update(['is_active' => false]);
            }

            return SendResult::fail($report->getReason() ?: 'Push gönderilemedi.', 'webpush');
        } catch (\Throwable $e) {
            return SendResult::fail($e->getMessage(), 'webpush');
        }
    }
}
