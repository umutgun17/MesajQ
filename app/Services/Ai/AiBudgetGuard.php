<?php

namespace App\Services\Ai;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-tenant LLM harcama freni.
 *
 * Amaç: bir kiracının (veya ele geçirilmiş bir token'ın) PAYLAŞIMLI platform API
 * anahtarını sınırsız harcayıp tüm kiracıları etkilemesini önlemek. Apify bütçe freninin
 * (APIFY_BUDGET_USD) LLM karşılığı.
 *
 * Mantık:
 *  - Kiracı kendi limitini `settings.ai_budget_usd` ile ayarladıysa o geçerlidir (0 = limitsiz).
 *  - Ayar yoksa: kiracı PAYLAŞIMLI platform anahtarını kullanıyorsa varsayılan aylık fren uygulanır
 *    (config services.ai.shared_budget_usd, default 25 USD); kendi anahtarını kullanıyorsa limitsiz
 *    (kendi faturasını kendi öder).
 *
 * Güvenli taraf: her hata durumunda İZİN verir (fail-open) — fren, meşru AI'ı asla kilitlemesin.
 * Maliyet tahminidir (token × model fiyatı); kesin faturalama değil, sadece kaçağı durduran brek.
 */
class AiBudgetGuard
{
    /** micro-USD / 1M token → [input, output]. 1 USD = 1_000_000 micro-USD. */
    protected const PRICING = [
        'claude'      => [2_000_000, 10_000_000], // Sonnet 5: $2 in / $10 out (4 Eyl güncel)
        'claude-haiku' => [1_000_000, 5_000_000], // Haiku 4.5: $1 in / $5 out
        'embedding'   => [20_000, 20_000],        // ~$0.02 / 1M (text-embedding-3-small)
        'openai-mini' => [150_000, 600_000],      // ~$0.15 in / $0.60 out (gpt-4o-mini, chatbot fallback)
        'groq'        => [590_000, 790_000],      // ~$0.59 in / $0.79 out (llama-3.3-70b)
    ];

    protected const TTL_DAYS = 40;

    public static function periodKey(?int $tenantId): string
    {
        return 'ai_spend_uusd:' . ($tenantId ?: 'shared') . ':' . now()->format('Y-m');
    }

    /**
     * Bütçe aşılmadıysa true döner (çağrıya izin). Limit yoksa veya hata olursa true (fail-open).
     */
    public static function allows(?int $tenantId, bool $usingSharedKey): bool
    {
        try {
            $limitUsd = self::limitUsd($tenantId, $usingSharedKey);
            if ($limitUsd === null) {
                return true; // limitsiz
            }

            $spent = (int) Cache::get(self::periodKey($tenantId), 0); // micro-USD
            return $spent < (int) round($limitUsd * 1_000_000);
        } catch (\Throwable $e) {
            Log::warning('AiBudgetGuard: allows() failed, allowing', ['error' => $e->getMessage()]);
            return true;
        }
    }

    /**
     * Bir çağrının tahmini maliyetini aylık sayaca ekler.
     *
     * @param  string  $kind  'claude' | 'embedding'
     */
    public static function record(?int $tenantId, int $inputTokens, int $outputTokens, string $kind = 'claude'): void
    {
        try {
            [$inRate, $outRate] = self::PRICING[$kind] ?? self::PRICING['claude'];
            $costMicro = (int) round($inputTokens / 1_000_000 * $inRate + $outputTokens / 1_000_000 * $outRate);
            if ($costMicro <= 0) {
                return;
            }

            $key = self::periodKey($tenantId);
            // TTL'li olarak yoksa oluştur, sonra artır (INCRBY TTL'yi korur).
            Cache::add($key, 0, now()->addDays(self::TTL_DAYS));
            Cache::increment($key, $costMicro);
        } catch (\Throwable $e) {
            Log::warning('AiBudgetGuard: record() failed', ['error' => $e->getMessage()]);
        }
    }

    /** Bu ay bu kiracı için harcanan tahmini USD (panel/analitik için). */
    public static function spentUsd(?int $tenantId): float
    {
        try {
            return ((int) Cache::get(self::periodKey($tenantId), 0)) / 1_000_000;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    protected static function limitUsd(?int $tenantId, bool $usingSharedKey): ?float
    {
        if ($tenantId) {
            $settings = Tenant::find($tenantId)?->settings ?? [];
            if (isset($settings['ai_budget_usd']) && is_numeric($settings['ai_budget_usd'])) {
                $v = (float) $settings['ai_budget_usd'];
                return $v > 0 ? $v : null; // 0 = kiracı bilinçli olarak limitsiz seçti
            }
        }

        if ($usingSharedKey) {
            $default = (float) config('services.ai.shared_budget_usd', 25);
            return $default > 0 ? $default : null;
        }

        return null; // kendi anahtarı, ayar yok → limitsiz
    }
}
