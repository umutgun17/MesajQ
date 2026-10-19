<?php

namespace App\Services\Marketing;

use App\Models\Channel;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp numara kalite bekçisi.
 *
 * Meta'nın quality_rating değeri (GREEN/YELLOW/RED) son 7 gündeki alıcı
 * geri bildirimlerinden (engelleme/şikâyet) hesaplanır ve numaranın
 * mesajlaşma limitinin düşürülmesine, sürerse kısıtlanmasına yol açar.
 * Aynı numara sipariş bildirimi + sepet kurtarma + müşteri mesajlaşmasını
 * da taşıdığı için kampanya hacmiyle riske atılamaz.
 *
 * Kural: kalite RED olduğunda gönderen kampanyalar OTOMATİK duraklatılır;
 * RED'den çıkınca (YELLOW/GREEN) yalnızca bu bekçinin durdurduğu kampanyalar
 * otomatik devam eder. Elle duraklatılanlara dokunulmaz. Her kalite geçişi
 * tenant ayarlarındaki geçmişe yazılır ve panelde gösterilir.
 */
class PhoneQualityGuard
{
    /** Otomatik duraklatma gerekçesi — auto-resume bu metinle eşleşerek çalışır, değiştirme. */
    public const PAUSE_REASON = 'Numara kalitesi DÜŞÜK (Meta: RED) — otomatik duraklatıldı, kalite düzelince otomatik devam eder';

    public const HISTORY_MAX = 20;

    /** Meta phoneInfo (10 dk cache) — panel ve dispatcher aynı kaynağı kullanır. */
    public static function info(int $tenantId): array
    {
        return Cache::remember("wa_quality_{$tenantId}", 600, function () use ($tenantId) {
            $channel = Channel::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('type', 'like', '%whatsapp%')
                ->where('is_active', true)
                ->first();
            if (! $channel) {
                return [];
            }
            $wa = app(WhatsAppService::class);
            $pnid = $wa->getPhoneNumberId($channel->settings ?? []);
            $token = $wa->getAccessToken($channel->credentials ?? []);
            if (! $pnid || ! $token) {
                return [];
            }

            return $wa->phoneInfo($pnid, $token);
        });
    }

    /** Cache'i düşür (webhook'tan taze veri gerektiğinde). */
    public static function forget(int $tenantId): void
    {
        Cache::forget("wa_quality_{$tenantId}");
    }

    /**
     * Kaliteyi oku, geçişi geçmişe işle, gerekiyorsa duraklat/devam ettir.
     *
     * @return array{rating: ?string, paused: int, resumed: int}
     */
    public static function enforce(int $tenantId, string $source = 'cron'): array
    {
        $out = ['rating' => null, 'paused' => 0, 'resumed' => 0];

        $rating = strtoupper((string) (self::info($tenantId)['quality_rating'] ?? ''));
        if (! in_array($rating, ['GREEN', 'YELLOW', 'RED'], true)) {
            // API erişilemedi/bilinmiyor: karar verme — mevcut durumu koru.
            return $out;
        }
        $out['rating'] = $rating;

        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            self::recordCheck($tenant, $rating, $source);
        }

        if ($rating === 'RED') {
            $out['paused'] = \App\Models\MarketingCampaign::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('channel', 'whatsapp')
                ->where('status', 'sending')
                ->update(['status' => 'paused', 'paused_reason' => self::PAUSE_REASON]);
            if ($out['paused'] > 0) {
                Log::warning("[quality-guard] t{$tenantId} kalite RED — {$out['paused']} kampanya otomatik duraklatıldı ({$source})");
            }
        } else {
            $out['resumed'] = \App\Models\MarketingCampaign::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('channel', 'whatsapp')
                ->where('status', 'paused')
                ->where('paused_reason', self::PAUSE_REASON)
                ->update(['status' => 'sending', 'paused_reason' => null]);
            if ($out['resumed'] > 0) {
                Log::warning("[quality-guard] t{$tenantId} kalite {$rating} — {$out['resumed']} kampanya otomatik devam ettirildi ({$source})");
            }
        }

        return $out;
    }

    /** Son kontrol zamanını ve (değiştiyse) kalite geçişini tenant ayarlarına yazar. */
    protected static function recordCheck(Tenant $tenant, string $rating, string $source): void
    {
        $s = $tenant->settings ?? [];
        $old = $s['wa_quality_rating'] ?? null;

        $s['wa_quality_rating'] = $rating;
        $s['wa_quality_checked_at'] = now()->toIso8601String();

        if ($old !== $rating) {
            $history = $s['wa_quality_history'] ?? [];
            $history[] = [
                'at' => now()->toIso8601String(),
                'from' => $old,
                'to' => $rating,
                'source' => $source,
            ];
            $s['wa_quality_history'] = array_slice($history, -self::HISTORY_MAX);
            Log::warning("[quality-guard] t{$tenant->id} numara kalitesi değişti: ".($old ?: '—')." → {$rating} ({$source})");
        }

        $tenant->update(['settings' => $s]);
    }

    /** Panel için şeffaf durum özeti. */
    public static function state(int $tenantId): array
    {
        $s = Tenant::find($tenantId)?->settings ?? [];

        return [
            'rating' => $s['wa_quality_rating'] ?? null,
            'checked_at' => $s['wa_quality_checked_at'] ?? null,
            'history' => array_reverse($s['wa_quality_history'] ?? []),
            'pause_reason' => self::PAUSE_REASON,
        ];
    }
}
