<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\DB;

/**
 * AI Chatbot — KADEMELİ AÇILIM. Bkz docs/ai-chatbot.md.
 *
 * Chatbot son tüketiciye doğrudan görünen bir yüzey; herkese birden açılmaz.
 * Panelden verilen kotaya kadar farklı ziyaretçi botu kullanır, kota dolunca
 * yeni ziyaretçilerde widget HİÇ render edilmez (mevcut kohort etkilenmez).
 * Kota büyütülünce (10 → 100 → sınırsız) kaldığı yerden devam eder.
 *
 * Slot tahsisi ilk MESAJDA yapılır: sayfayı açıp yazmayan ziyaretçi kotayı
 * yakmaz, böylece "10 kişinin konuşmasını inceleyelim" hedefi tutar.
 * Test trafiği (isTestTraffic) kotadan sayılmaz.
 *
 * Modlar (chat_rollout_mode):
 *   off     — widget kapalı
 *   limited — kotalı pilot (varsayılan; chat_rollout_limit kadar ziyaretçi)
 *   all     — herkese açık
 */
class ChatRollout
{
    /** Ziyaretçi widget'ı görebilir mi? (config çağrısında sorulur) */
    public static function canSee(int $tenantId, array $settings, ?string $visitorId): bool
    {
        return match ($settings['rollout_mode']) {
            'off' => false,
            'all' => true,
            default => static::isMember($tenantId, $visitorId)
                || static::count($tenantId) < max(0, (int) $settings['rollout_limit']),
        };
    }

    /**
     * Ziyaretçi yazmaya kalktı — slotu tahsis et.
     * false = kota dolu ve bu ziyaretçi kohortta değil (mesaj işlenmemeli).
     */
    public static function claim(int $tenantId, array $settings, ?string $visitorId, bool $isTest = false): bool
    {
        if ($settings['rollout_mode'] === 'off') {
            return false;
        }
        if ($settings['rollout_mode'] === 'all' || $isTest || ! $visitorId) {
            return true;
        }
        if (static::isMember($tenantId, $visitorId)) {
            return true;
        }
        if (static::count($tenantId) >= max(0, (int) $settings['rollout_limit'])) {
            return false;
        }

        // Yarış durumunda unique index koruyor; çakışma = zaten üye
        try {
            DB::table('chat_rollout_visitors')->insert([
                'tenant_id' => $tenantId,
                'visitor_id' => $visitorId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return static::isMember($tenantId, $visitorId);
        }

        return true;
    }

    public static function isMember(int $tenantId, ?string $visitorId): bool
    {
        if (! $visitorId) {
            return false;
        }

        return DB::table('chat_rollout_visitors')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->exists();
    }

    /** Kotadan sayılan (kohorta girmiş) ziyaretçi sayısı. */
    public static function count(int $tenantId): int
    {
        return (int) DB::table('chat_rollout_visitors')->where('tenant_id', $tenantId)->count();
    }

    /** Panel özeti: kaç kişi girdi, kota ne, kaç kişi gerçekten yazdı. */
    public static function stats(int $tenantId, array $settings): array
    {
        $used = static::count($tenantId);
        $limit = max(0, (int) $settings['rollout_limit']);

        return [
            'mode' => $settings['rollout_mode'],
            'limit' => $limit,
            'used' => $used,
            'remaining' => $settings['rollout_mode'] === 'limited' ? max(0, $limit - $used) : null,
            'full' => $settings['rollout_mode'] === 'limited' && $used >= $limit,
        ];
    }

    /** Kohortu sıfırla (panelden "pilotu baştan başlat"). */
    public static function reset(int $tenantId): int
    {
        return DB::table('chat_rollout_visitors')->where('tenant_id', $tenantId)->delete();
    }
}
