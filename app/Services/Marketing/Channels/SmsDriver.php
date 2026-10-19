<?php

namespace App\Services\Marketing\Channels;

use App\Models\Channel;
use App\Services\Marketing\SendResult;
use App\Services\NetgsmService;

/**
 * SMS kanalı — mevcut NetGSM entegrasyonunu kullanır.
 * Kimlikler tenant'ın netgsm* kanalında (netgsm_usercode/password).
 * Gönderici başlığı (msgheader) NetGSM'de onaylı olmalı; kanal settings'ten
 * (sms_header) ya da gönderim opsiyonundan (header) gelir.
 */
class SmsDriver implements ChannelDriverInterface
{
    public function key(): string
    {
        return 'sms';
    }

    protected function channel(int $tenantId): ?Channel
    {
        return Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'like', 'netgsm%')
            ->where('is_active', true)
            ->first();
    }

    public function isAvailable(int $tenantId): bool
    {
        $ch = $this->channel($tenantId);

        return $ch !== null && NetgsmService::fromChannel($ch)->isAvailable();
    }

    public function send(int $tenantId, string $to, array $message): SendResult
    {
        $ch = $this->channel($tenantId);
        if (! $ch) {
            return SendResult::fail('NetGSM kanalı bulunamadı (tenant için netgsm* kanalı yok).', 'netgsm');
        }

        $svc = NetgsmService::fromChannel($ch);
        if (! $svc->isAvailable()) {
            return SendResult::fail('NetGSM kimlikleri eksik (usercode/password).', 'netgsm');
        }

        $header = $message['header']
            ?? ($ch->settings['sms_header'] ?? null)
            ?? (\App\Models\Tenant::withoutGlobalScopes()->find($tenantId)?->settings['sms_header'] ?? null);
        if (! $header) {
            return SendResult::fail('SMS gönderici başlığı (msgheader) tanımlı değil.', 'netgsm');
        }

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            return SendResult::fail('Boş SMS metni.', 'netgsm');
        }

        // Türkçe/Unicode karakter varsa REST v2 (TR) ile gönder → tam Türkçe (ç ğ ı ş korunur).
        // Saf ASCII ise null → mevcut GET yolu (değişmeden).
        $encoding = preg_match('/[^\x00-\x7F]/', $body) ? 'tr' : null;
        $res = $svc->sendSms($to, $body, $header, $encoding);

        return new SendResult(
            success: (bool) $res['success'],
            providerMessageId: $res['code'] ?? null,
            provider: 'netgsm',
            error: $res['error'] ?? null,
        );
    }
}
