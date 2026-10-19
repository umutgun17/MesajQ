<?php

namespace App\Services\Crm;

/**
 * Siparişin ödeme yöntemi (CRM Siparişler / Gecikmeler filtresi ve rozeti).
 *
 * Kaynak: orders.payments (Ticimax Odemeler). Siparişte birden çok deneme satırı
 * olabilir (PayTR reddi → kapıda ödeme gibi); önceliği DailyReportCollector::classifyPayment
 * ile aynı: kapıda ödeme satırı varsa kapıda, yoksa kart (17 iyzico / 25 PayTR),
 * yoksa pazaryeri (10), havale (1). SQL ifadesi ile PHP sınıflandırıcısı aynı kuralı uygular.
 */
class PaymentMethod
{
    public const LABELS = [
        'cod_cash' => 'Kapıda nakit',
        'cod_card' => 'Kapıda kart',
        'card' => 'Kredi kartı (online)',
        'marketplace' => 'Pazaryeri ödemesi',
        'transfer' => 'Havale / EFT',
        'other' => 'Diğer / bilinmiyor',
    ];

    /** Filtre değerleri: 'cod' = iki kapıda türü birden. */
    public static function expand(string $csv): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $csv))) as $k) {
            if ($k === 'cod') {
                array_push($keys, 'cod_cash', 'cod_card');
            } elseif (isset(self::LABELS[$k])) {
                $keys[] = $k;
            }
        }

        return array_values(array_unique($keys));
    }

    /** orders tablosu için CASE ifadesi (tutarı > 0 satırlar). */
    public static function sql(string $col = 'payments'): string
    {
        $rows = "jsonb_array_elements(CASE WHEN jsonb_typeof({$col}) = 'array' THEN {$col} ELSE '[]'::jsonb END) p";
        $has = fn (string $cond) => "EXISTS (SELECT 1 FROM {$rows} WHERE COALESCE((p->>'amount')::numeric, 0) > 0 AND ({$cond}))";
        $cod = "COALESCE((p->>'cod_amount')::numeric, 0) > 0 OR p->>'type' IN ('2','3')";

        return 'CASE'
            . ' WHEN ' . $has("p->>'type' = '3'") . " THEN 'cod_card'"
            . ' WHEN ' . $has($cod) . " THEN 'cod_cash'"
            . ' WHEN ' . $has("p->>'type' IN ('17','25')") . " THEN 'card'"
            . ' WHEN ' . $has("p->>'type' = '10'") . " THEN 'marketplace'"
            . ' WHEN ' . $has("p->>'type' = '1'") . " THEN 'transfer'"
            . " ELSE 'other' END";
    }

    /** Eloquent/Query sorgusuna ödeme yöntemi filtresi. */
    public static function apply($query, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $query->whereRaw('(' . self::sql() . ") IN ({$in})", $keys);
    }

    public static function classify(?array $payments): string
    {
        $types = [];
        $cod = false;
        foreach ($payments ?? [] as $p) {
            if ((float) ($p['amount'] ?? 0) <= 0) {
                continue;
            }
            $type = (int) ($p['type'] ?? 0);
            $types[$type] = true;
            if ((float) ($p['cod_amount'] ?? 0) > 0 || in_array($type, [2, 3], true)) {
                $cod = true;
            }
        }

        return match (true) {
            isset($types[3]) => 'cod_card',
            $cod => 'cod_cash',
            isset($types[17]) || isset($types[25]) => 'card',
            isset($types[10]) => 'marketplace',
            isset($types[1]) => 'transfer',
            default => 'other',
        };
    }
}
