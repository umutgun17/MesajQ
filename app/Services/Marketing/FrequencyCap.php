<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessage;

/**
 * Frekans limiti — aynı kişiye belirli sürede en fazla N pazarlama mesajı.
 * settings: marketing_freq_enabled (bool), marketing_freq_per_day (int, 0=sınırsız),
 *           marketing_freq_per_week (int, 0=sınırsız).
 *
 * Kişi eşleştirme: customer_id varsa onunla (kanallar arası), yoksa recipient ile.
 */
class FrequencyCap
{
    public static function enabled(array $s): bool
    {
        return ! empty($s['marketing_freq_enabled']);
    }

    public static function perDay(array $s): int
    {
        return (int) ($s['marketing_freq_per_day'] ?? 0);
    }

    public static function perWeek(array $s): int
    {
        return (int) ($s['marketing_freq_per_week'] ?? 0);
    }

    /** Bu kişi için frekans limiti aşıldı mı? (gönderme/ertele kararı için) */
    public static function exceeded(int $tenantId, array $s, ?int $customerId, string $recipient): bool
    {
        if (! self::enabled($s)) {
            return false;
        }
        $perDay = self::perDay($s);
        $perWeek = self::perWeek($s);
        if ($perDay <= 0 && $perWeek <= 0) {
            return false;
        }

        $base = fn () => MarketingMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'sent')
            ->where(function ($q) use ($customerId, $recipient) {
                $q->where('recipient', $recipient);
                if ($customerId) {
                    $q->orWhere('customer_id', $customerId);
                }
            });

        if ($perDay > 0 && $base()->where('created_at', '>=', now()->subDay())->count() >= $perDay) {
            return true;
        }
        if ($perWeek > 0 && $base()->where('created_at', '>=', now()->subWeek())->count() >= $perWeek) {
            return true;
        }

        return false;
    }
}
