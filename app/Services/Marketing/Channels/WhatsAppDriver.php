<?php

namespace App\Services\Marketing\Channels;

use App\Models\Channel;
use App\Services\Marketing\SendResult;
use App\Services\WhatsAppService;

/**
 * WhatsApp kanalı — Meta WhatsApp Cloud API (mevcut WhatsAppService).
 *
 * NOT: Proaktif gönderim (24 saatlik müşteri penceresi DIŞI) Meta onaylı TEMPLATE
 * gerektirir; serbest metin yalnızca 24s oturum içinde çalışır. phone_number_id
 * yoksa kanal KAPALI (isAvailable=false) → sepet kurtarmada bu adım atlanır.
 */
class WhatsAppDriver implements ChannelDriverInterface
{
    public function key(): string
    {
        return 'whatsapp';
    }

    protected function channel(int $tenantId): ?Channel
    {
        return Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('type', 'like', '%whatsapp%')->orWhere('type', 'like', 'meta%'))
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN type LIKE '%whatsapp%' THEN 0 ELSE 1 END")
            ->first();
    }

    public function isAvailable(int $tenantId): bool
    {
        $ch = $this->channel($tenantId);
        if (! $ch) {
            return false;
        }
        $svc = new WhatsAppService();

        return $svc->getAccessToken($ch->credentials ?? []) && $svc->getPhoneNumberId($ch->settings ?? []);
    }

    public function send(int $tenantId, string $to, array $message): SendResult
    {
        $ch = $this->channel($tenantId);
        if (! $ch) {
            return SendResult::fail('WhatsApp kanalı bulunamadı.', 'whatsapp');
        }

        $svc = new WhatsAppService();
        $token = $svc->getAccessToken($ch->credentials ?? []);
        $phoneId = $svc->getPhoneNumberId($ch->settings ?? []);

        if (! $token || ! $phoneId) {
            return SendResult::fail('WhatsApp access_token / phone_number_id eksik.', 'whatsapp');
        }

        // Numara normalizasyonu: Meta uluslararası format ister ("905xxxxxxxxx").
        // "+90 543...", "0543...", "543..." varyantlarını tek biçime indirger.
        $to = preg_replace('/\D+/', '', $to);
        if (str_starts_with($to, '0')) {
            $to = '9' . $to;                    // 0543... → 90543...
        } elseif (strlen($to) === 10 && str_starts_with($to, '5')) {
            $to = '90' . $to;                   // 543... → 90543...
        }
        if ($to === '') {
            return SendResult::fail('Geçersiz telefon numarası.', 'whatsapp');
        }

        // Meta onaylı TEMPLATE gönderimi (proaktif — 24s penceresi dışında zorunlu yol).
        // message['template'] = ['name' => ..., 'lang' => 'tr', 'params' => [sıralı {{1}},{{2}}... değerleri],
        //                        'url_button_param' => 'dinamik URL butonunun {{1}} son eki (ops.)']
        $template = $message['template'] ?? null;
        if (is_array($template) && trim((string) ($template['name'] ?? '')) !== '') {
            $params = array_map(
                fn ($p) => ['type' => 'text', 'text' => (string) $p],
                array_values($template['params'] ?? [])
            );
            $components = $params !== [] ? [['type' => 'body', 'parameters' => $params]] : [];

            // Dinamik URL butonu (ör. kargo takip): şablondaki buton URL'sinin {{1}} son eki
            $urlParam = trim((string) ($template['url_button_param'] ?? ''));
            if ($urlParam !== '') {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => $urlParam]],
                ];
            }

            $res = $svc->sendTemplate($to, $template['name'], (string) ($template['lang'] ?? 'tr'), $components, $phoneId, $token);
            $this->throwIfTransient($res, $to);

            return new SendResult(
                success: (bool) ($res['success'] ?? false),
                providerMessageId: $res['message_id'] ?? null,
                provider: 'whatsapp',
                error: ($res['success'] ?? false) ? null : (is_string($res['error'] ?? null) ? $res['error'] : json_encode($res['error'] ?? 'hata')),
            );
        }

        $text = trim((string) ($message['body'] ?? ''));
        if ($text === '') {
            return SendResult::fail('Boş WhatsApp mesajı.', 'whatsapp');
        }

        $res = $svc->sendMessage($to, $text, $phoneId, $token);
        $this->throwIfTransient($res, $to);

        return new SendResult(
            success: (bool) ($res['success'] ?? false),
            providerMessageId: $res['message_id'] ?? null,
            provider: 'whatsapp',
            error: ($res['success'] ?? false) ? null : (is_string($res['error'] ?? null) ? $res['error'] : json_encode($res['error'] ?? 'hata')),
        );
    }

    /**
     * Ağ/zaman aşımı gibi GEÇİCİ hatalarda istisna fırlatır ki kuyruk işi
     * yeniden denesin. Aksi halde gönderim "kalıcı başarısız" yazılır ve
     * sipariş/kargo bildirimi bir daha hiç gitmez (9 Eyl ağ kesintisi dersi).
     * Panelden elle gönderimde bu yol kullanılmaz — orada hata ekranda gösterilir.
     */
    protected function throwIfTransient(array $res, string $to): void
    {
        if (! ($res['success'] ?? false) && ($res['transient'] ?? false)) {
            throw new \Illuminate\Http\Client\ConnectionException(
                'WhatsApp geçici bağlantı hatası (' . $to . ') — kuyruk yeniden deneyecek.'
            );
        }
    }
}
