<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;

/**
 * Aylık kullanım sayaçları (plan limitleri için). Limit aşımı sert kilit DEĞİLDİR:
 * panelde uyarı + bir sonraki ay üst bareme geçiş önerisi (fiyat sayfası vaadi).
 */
class UsageMeter
{
    public static function increment(int $tenantId, string $metric, int $by = 1): int
    {
        $period = now()->format('Y-m');
        DB::table('usage_counters')->upsert(
            [['tenant_id' => $tenantId, 'metric' => $metric, 'period' => $period, 'value' => $by, 'created_at' => now(), 'updated_at' => now()]],
            ['tenant_id', 'metric', 'period'],
            ['value' => DB::raw('usage_counters.value + ' . (int) $by), 'updated_at' => now()],
        );

        return (int) UsageCounter::where(compact('metric', 'period'))->where('tenant_id', $tenantId)->value('value');
    }

    public static function current(int $tenantId, string $metric): int
    {
        return (int) UsageCounter::where('tenant_id', $tenantId)->where('metric', $metric)->where('period', now()->format('Y-m'))->value('value');
    }

    /** @return array<string,array{used:int,limit:?int,ratio:?float}> */
    public static function summary(Tenant $tenant): array
    {
        $limits = $tenant->settings['plan_limits'] ?? [];
        $metrics = array_unique(array_merge(array_keys($limits), array_keys(config('saas.metrics', []))));
        $out = [];
        foreach ($metrics as $m) {
            $used = self::current($tenant->id, $m);
            $limit = isset($limits[$m]) ? (int) $limits[$m] : null;
            $out[$m] = [
                'label' => config("saas.metrics.$m", $m),
                'used' => $used,
                'limit' => $limit,
                'ratio' => $limit ? round($used / max(1, $limit), 3) : null,
            ];
        }

        return $out;
    }

    public static function isOverLimit(Tenant $tenant, string $metric): bool
    {
        $limit = $tenant->settings['plan_limits'][$metric] ?? null;

        return $limit !== null && self::current($tenant->id, $metric) >= (int) $limit;
    }
}
