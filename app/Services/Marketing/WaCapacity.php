<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\DB;

/**
 * WhatsApp 24 saatlik tekil-alıcı kapasitesi.
 *
 * Meta'nın mesajlaşma limiti (messaging tier) "son 24 saatte işletme-başlatmalı
 * konuşma açılan TEKİL kişi" sayısıdır. Graph API bu tier'ı bu app'in erişim
 * seviyesinde döndürmüyor → limit panelden elle ayarlanır (wa_daily_unique_limit).
 * Sipariş bildirimi / sepet kurtarma gibi otomasyonlar da aynı limitten yediği
 * için kampanya payı = limit - rezerv - son 24s kullanılan tekil alıcı.
 */
class WaCapacity
{
    public static function limit(array $s): int
    {
        return max(0, (int) ($s['wa_daily_unique_limit'] ?? 1000));
    }

    /** Otomasyonlara (sipariş bildirimi vb.) ayrılan pay — kampanya buna dokunamaz. */
    public static function reserve(array $s): int
    {
        return max(0, (int) ($s['wa_reserve_for_automations'] ?? 300));
    }

    /** Son 24 saatte WhatsApp'tan mesaj GÖNDERİLEN tekil alıcı sayısı (tüm kaynaklar). */
    public static function used24h(int $tenantId): int
    {
        // Otomasyon + kampanya + manuel: marketing_messages
        $marketing = (int) DB::table('marketing_messages')
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subDay())
            ->selectRaw("count(distinct right(regexp_replace(recipient, '\\D', '', 'g'), 10)) as c")
            ->value('c');

        // Panelden "Şablonla başlat" ile açılan konuşmalar (işletme-başlatmalı)
        $inbox = (int) DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('channels', 'channels.id', '=', 'conversations.channel_id')
            ->where('channels.tenant_id', $tenantId)
            ->where('channels.type', 'like', '%whatsapp%')
            ->where('messages.sender_type', 'agent')
            ->whereRaw("messages.metadata->>'whatsapp_template' is not null")
            ->where('messages.created_at', '>=', now()->subDay())
            ->distinct('conversations.id')
            ->count('conversations.id');

        // İki küme kesişebilir; üst sınır güvenli taraf (fazla saymak eksik saymaktan iyi).
        return $marketing + $inbox;
    }

    /** Kampanyanın ŞU AN gönderebileceği maksimum tekil alıcı. */
    public static function remainingForCampaigns(int $tenantId, array $s): int
    {
        return max(0, self::limit($s) - self::reserve($s) - self::used24h($tenantId));
    }

    public static function summary(int $tenantId, array $s): array
    {
        $limit = self::limit($s);
        $reserve = self::reserve($s);
        $used = self::used24h($tenantId);

        return [
            'limit' => $limit,
            'reserve' => $reserve,
            'used_24h' => $used,
            'remaining' => max(0, $limit - $reserve - $used),
        ];
    }
}
