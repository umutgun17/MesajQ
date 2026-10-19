<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp giden-mesaj statülerini (sent/delivered/read/failed) işler.
 * Yalnızca kendi kayıtlarımızı günceller — müşteriye okundu bilgisi
 * (mavi tik) GÖNDERİLMEZ (bilinçli ürün kararı).
 */
class ProcessWhatsAppStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Statü sıralaması — geç gelen düşük statü yükseği ezmesin. */
    protected const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    /** Sık görülen Meta hata kodları için Türkçe açıklama. */
    protected const ERROR_TR = [
        131026 => 'Numaraya ulaşılamıyor (WhatsApp yok veya mesaj alamıyor)',
        131047 => '24 saatlik mesaj penceresi kapalı — şablon gerekli',
        131048 => 'Spam limiti — gönderim geçici olarak kısıtlandı',
        131049 => 'Meta pazarlama limitine takıldı, iletilmedi',
        131053 => 'Medya yüklenemedi',
        130472 => 'Alıcı Meta deneyine dahil, iletilemedi',
    ];

    public function __construct(public array $data)
    {
    }

    public function handle(): void
    {
        $wamid  = $this->data['message_id'] ?? null;
        $status = $this->data['status'] ?? null;
        if (! $wamid || ! isset(self::RANK[$status])) {
            return;
        }

        $statusAt = ! empty($this->data['timestamp'])
            ? date('c', (int) $this->data['timestamp'])
            : now()->toISOString();

        $this->updateInboxMessage($wamid, $status, $statusAt);
        $this->updateMarketingMessage($wamid, $status, $statusAt);
    }

    protected function updateInboxMessage(string $wamid, string $status, string $statusAt): void
    {
        $msg = Message::withoutGlobalScopes()->where('platform_message_id', $wamid)->first();
        if (! $msg) {
            return;
        }

        $meta = (array) ($msg->metadata ?? []);
        $currentRank = self::RANK[$meta['wa_status'] ?? ''] ?? 0;

        if (self::RANK[$status] <= $currentRank) {
            return; // aynı veya daha eski statü — dokunma
        }

        $meta['wa_status']    = $status;
        $meta['wa_status_at'] = $statusAt;

        if ($status === 'failed') {
            $meta['delivery_error']    = $this->humanError($this->data['errors'] ?? null);
            $meta['delivery_error_at'] = $statusAt;
            $meta['wa_errors']         = $this->data['errors'] ?? null;

            Log::warning('ProcessWhatsAppStatus: message delivery failed', [
                'message_id' => $msg->id,
                'wamid'      => $wamid,
                'recipient'  => $this->data['recipient_id'] ?? null,
                'errors'     => $this->data['errors'] ?? null,
            ]);
        }

        $msg->metadata = $meta;
        $msg->save();
    }

    protected function updateMarketingMessage(string $wamid, string $status, string $statusAt): void
    {
        $updates = match ($status) {
            'delivered' => ['delivered_at' => $statusAt],
            'read'      => ['opened_at' => $statusAt],
            'failed'    => ['error' => json_encode([
                'delivery_failed' => $this->humanError($this->data['errors'] ?? null),
                'errors'          => $this->data['errors'] ?? null,
            ], JSON_UNESCAPED_UNICODE)],
            default     => null,
        };
        if (! $updates) {
            return;
        }

        // status alanına DOKUNMUYORUZ (retry akışını tetiklememek için);
        // teslim bilgisi delivered_at/opened_at/error üzerinden okunur.
        DB::table('marketing_messages')
            ->where('provider_message_id', $wamid)
            ->when($status === 'delivered', fn ($q) => $q->whereNull('delivered_at'))
            ->when($status === 'read', fn ($q) => $q->whereNull('opened_at'))
            ->update(array_merge($updates, ['updated_at' => now()]));
    }

    protected function humanError(?array $errors): string
    {
        $first = $errors[0] ?? [];
        $code  = $first['code'] ?? null;

        if ($code && isset(self::ERROR_TR[$code])) {
            return self::ERROR_TR[$code] . " (#{$code})";
        }

        $title = $first['title'] ?? $first['message'] ?? 'WhatsApp mesajı iletilemedi';

        return $code ? "{$title} (#{$code})" : $title;
    }
}
