<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\EmailSuppression;
use App\Models\MarketingMessage;
use App\Services\Marketing\Channels\ChannelDriverInterface;
use App\Services\Marketing\Channels\EmailDriver;
use App\Services\Marketing\Channels\PushDriver;
use App\Services\Marketing\Channels\SmsDriver;
use App\Services\Marketing\Channels\WhatsAppDriver;

/**
 * Pazarlama Otomasyonu — ortak gönderim katmanı.
 *
 * Tek giriş noktası: send(). Her gönderim:
 *   1) uygun kanal driver'ına yönlendirilir,
 *   2) gönderim öncesi kapılardan geçer (consent/İYS, sessiz saat, frekans limiti),
 *   3) her zaman marketing_messages tablosuna loglanır (sent/failed/skipped).
 *
 * Şu an bağlı kanal: SMS (NetGSM). email/whatsapp/push driver'ları eklenecek.
 */
class UnifiedSender
{
    /** @var array<string,ChannelDriverInterface> */
    protected array $drivers;

    public function __construct()
    {
        $this->drivers = [
            'sms' => new SmsDriver(),
            'push' => new PushDriver(),
            'email' => new EmailDriver(),
            'whatsapp' => new WhatsAppDriver(),
        ];
    }

    public function driver(string $channel): ?ChannelDriverInterface
    {
        return $this->drivers[$channel] ?? null;
    }

    /**
     * Mesaj gönder ve logla.
     *
     * @param array $message ['body'=>..., 'subject'=>..., 'header'=>..., 'template_id'=>...]
     * @param array $context ['source'=>..., 'source_type'=>..., 'customer_id'=>..., 'skip_gates'=>bool]
     */
    public function send(int $tenantId, string $channel, string $to, array $message, array $context = []): MarketingMessage
    {
        $log = new MarketingMessage([
            'channel' => $channel,
            'recipient' => $to,
            'source' => $context['source'] ?? null,
            'source_type' => $context['source_type'] ?? 'manual',
            'template_id' => $message['template_id'] ?? null,
            'subject' => $message['subject'] ?? null,
            'body' => $message['body'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'tracking_token' => $context['tracking_token'] ?? null,
            'meta' => $context['meta'] ?? null,
            'status' => 'queued',
        ]);
        $log->tenant_id = $tenantId; // CLI/queue bağlamında auth yok — açıkça set

        $driver = $this->driver($channel);
        if (! $driver) {
            return $this->skip($log, 'channel_unsupported');
        }
        if (! $driver->isAvailable($tenantId)) {
            return $this->skip($log, 'channel_disabled');
        }

        // Bastırma listesi (şikayet / "numaramı silin" / yanlış numara) — skip_gates
        // olsa bile ASLA gönderme; transactional bildirimler dahil.
        if (\App\Models\MarketingSuppression::isSuppressed($tenantId, $channel, $to)) {
            return $this->skip($log, 'suppressed');
        }

        // Gönderim öncesi kapılar (test/transactional için skip_gates ile atlanabilir)
        if (empty($context['skip_gates'])) {
            if ($reason = $this->gateBlock($tenantId, $channel, $to, $context)) {
                return $this->skip($log, $reason);
            }
        }

        $result = $driver->send($tenantId, $to, $message);

        $log->provider = $result->provider;
        $log->provider_message_id = $result->providerMessageId;
        $log->cost = $result->cost;

        if ($result->success) {
            $log->status = 'sent';
            $log->sent_at = now();
        } else {
            $log->status = 'failed';
            $log->error = $result->error;
        }
        $log->save();

        return $log;
    }

    protected function skip(MarketingMessage $log, string $reason): MarketingMessage
    {
        $log->status = 'skipped';
        $log->skip_reason = $reason;
        $log->save();

        return $log;
    }

    /**
     * Gönderim öncesi kapılar. Engel varsa skip_reason döner, yoksa null.
     *
     * İSKELET: consent/İYS, sessiz saat penceresi ve frekans limiti buraya
     * eklenecek. Şimdilik yalnızca consent (customer biliniyorsa) kontrol edilir.
     */
    protected function gateBlock(int $tenantId, string $channel, string $to, array $context): ?string
    {
        // E-posta: abonelikten çıkmış/bounce/şikayet adreslerine ASLA gönderme
        if ($channel === 'email' && EmailSuppression::isSuppressed($tenantId, $to)) {
            return 'unsubscribed';
        }

        // Gönderim saat aralığı (TR) — dışındaysa gönderme (transactional skip_gates ile atlar)
        $settings = \App\Models\Tenant::withoutGlobalScopes()->find($tenantId)?->settings ?? [];
        if (! SendWindow::isOpen($settings)) {
            return 'quiet_hours';
        }

        // Frekans limiti — aynı kişiye çok mesaj gitmesin
        if (FrequencyCap::exceeded($tenantId, $settings, $context['customer_id'] ?? null, $to)) {
            return 'frequency_cap';
        }

        $customerId = $context['customer_id'] ?? null;
        if ($customerId) {
            $customer = Customer::withoutGlobalScopes()->find($customerId);
            if ($customer) {
                // Ticimax izin bayrağı (sms_consent/mail_consent) kontrolleri tenant
                // ayarıyla kapatılabilir (kullanıcı kararı 2026-07-10: Tonny Black'te
                // İKİSİ DE KAPALI — İYS retlerini NetGSM B021 uyguluyor; unsubscribe/
                // şikayet bastırmaları AYRI mekanizma olup her zaman aktif kalır).
                if ($channel === 'sms' && ($settings['marketing_check_sms_consent'] ?? true) && ! $customer->sms_consent) {
                    return 'consent';
                }
                if ($channel === 'email' && ($settings['marketing_check_mail_consent'] ?? true) && ! $customer->mail_consent) {
                    return 'consent';
                }
            }
        }

        // TODO: sessiz saat penceresi (quiet_hours), frekans limiti (frequency_cap), İYS.
        return null;
    }
}
