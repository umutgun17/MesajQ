<?php

namespace App\Services\Crm;

use App\Models\Order;
use App\Services\AppPromo\TenantAppPromoSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRM sipariş kartındaki "Toplam" açıklaması: hangi indirim/kupon uygulandı, kargo kaç TL,
 * vade farkı var mı. Ticimax alanlarından (orders sütunları + metadata.discounts) üretilir;
 * kupon türü koda göre sınıflandırılır (çark, uygulama, SMS, sepet kurtarma, diğer).
 *
 * Çıktı (order->pricing):
 *  products  : indirim öncesi ürün toplamı (KDV dahil)
 *  lines[]   : {type, label, amount, code?, detail?}  amount: indirimler negatif, eklemeler pozitif
 *  discount  : toplam indirim (pozitif sayı)
 *  shipping  : kargo ücreti
 *  surcharge : vade farkı
 */
class OrderPricingBreakdown
{
    public function apply(Collection $orders, int $tenantId): void
    {
        $codes = $orders->pluck('coupon_code')->filter()->map(fn ($c) => mb_strtoupper(trim($c)))->unique()->values();

        $wheelCodes = [];
        if ($codes->isNotEmpty()) {
            $wheelCodes = DB::table('wheel_spins')
                ->where('tenant_id', $tenantId)
                ->whereIn(DB::raw('upper(coupon_code)'), $codes->all())
                ->pluck('coupon_code')
                ->map(fn ($c) => mb_strtoupper($c))
                ->flip()
                ->all();
        }

        $appCodes = [];
        try {
            $rules = TenantAppPromoSettings::get($tenantId)['rules'] ?? [];
            $appCodes = array_flip(array_map('mb_strtoupper', TenantAppPromoSettings::allCodes($rules)));
        } catch (\Throwable) {
            // ayar yoksa yalnız kalıp eşleşmesi
        }

        foreach ($orders as $o) {
            $o->setAttribute('pricing', $this->forOrder($o, $wheelCodes, $appCodes));
        }
    }

    public function forOrder(Order $o, array $wheelCodes = [], array $appCodes = []): array
    {
        $meta = is_array($o->metadata) ? ($o->metadata['discounts'] ?? []) : [];
        $total = (float) $o->total_amount;
        $shipping = (float) $o->shipping_cost;
        $discount = (float) $o->discount_amount;
        $gift = (float) $o->gift_card_amount;
        $points = (float) $o->points_discount;
        $campaign = round(max(0.0, $discount - $gift - $points), 2);
        $giftWrap = (float) ($meta['gift_wrap'] ?? 0);

        // Toplamı yukarı çeken ödeme kalemleri: vade farkı (banka komisyonu) veya kapıda ödeme ücreti.
        // Ödeme kaydı varsa oradan; yoksa toplam − (ürün + kargo) farkından (≥1 TL) çıkarılır.
        $productsGross = round((float) $o->subtotal + (float) $o->tax_amount - ($shipping > 0 ? $shipping - $shipping / 1.2 : 0), 2);
        $diff = round($total - $productsGross - $shipping - $giftWrap, 2);
        $surcharge = 0.0;
        $surchargeType = 'installment';
        $installments = 0;
        $isCod = false;
        $hasPayments = is_array($o->payments) && $o->payments !== [];
        if ($hasPayments) {
            foreach ($o->payments as $p) {
                if (empty($p['approved'])) {
                    continue;
                }
                $surcharge += (float) ($p['bank_commission'] ?? 0);
                $installments = max($installments, (int) ($p['installments'] ?? 0));
                if (in_array((int) ($p['type'] ?? 0), [2, 3], true) || (float) ($p['cod_amount'] ?? 0) > 0) {
                    $isCod = true;
                }
            }
            if ($isCod && $surcharge < 0.5 && $diff >= 1) {
                $surcharge = $diff;
                $surchargeType = 'cod_fee';
            }
        } elseif ($diff >= 1) {
            $surcharge = $diff;
        }
        $surcharge = round($surcharge, 2);

        $lines = [];
        $products = round($total - $shipping - $surcharge - $giftWrap + $discount, 2);
        $lines[] = ['type' => 'products', 'label' => 'Ürün toplamı', 'amount' => $products];

        if ($gift > 0 || $o->coupon_code) {
            [$type, $label] = $this->couponType($o->coupon_code, $wheelCodes, $appCodes);
            $detail = [];
            if (! empty($meta['coupon_percent'])) {
                $detail[] = '%' . rtrim(rtrim(number_format((float) $meta['coupon_percent'], 2, '.', ''), '0'), '.');
            }
            $lines[] = ['type' => $type, 'label' => $label, 'amount' => -$gift, 'code' => $o->coupon_code, 'detail' => $detail ? implode(' ', $detail) : null];
        }
        if ($points > 0) {
            $lines[] = ['type' => 'points', 'label' => 'Puan indirimi', 'amount' => -$points];
        }
        $splitLines = $campaign > 0 ? $this->campaignSplitLines($meta, $campaign, $products) : null;
        if ($splitLines) {
            array_push($lines, ...$splitLines);
        } elseif ($campaign > 0) {
            $names = collect($meta['campaigns'] ?? [])->pluck('name')->filter()->values();
            $isCart = ! empty($meta['cart_campaign']) || $names->contains(fn ($n) => preg_match('/sepet|üzeri|üzerine|tl ve/iu', $n));
            $lines[] = [
                'type' => $isCart ? 'cart_campaign' : 'campaign',
                'label' => $isCart ? 'Sepet kampanyası' : 'Kampanya indirimi',
                'amount' => -$campaign,
                'detail' => $names->isNotEmpty() ? $names->implode(' + ') : null,
            ];
        }
        if ($giftWrap > 0) {
            $lines[] = ['type' => 'gift_wrap', 'label' => 'Hediye paketi', 'amount' => $giftWrap];
        }
        $lines[] = $shipping > 0
            ? ['type' => 'shipping', 'label' => 'Kargo', 'amount' => $shipping]
            : ['type' => 'shipping_free', 'label' => 'Ücretsiz kargo', 'amount' => 0.0];
        if ($surcharge > 0) {
            $lines[] = $surchargeType === 'cod_fee'
                ? ['type' => 'cod_fee', 'label' => 'Kapıda ödeme ücreti', 'amount' => $surcharge]
                : ['type' => 'installment', 'label' => $hasPayments ? 'Vade farkı' : 'Vade/ödeme farkı', 'amount' => $surcharge, 'detail' => $installments > 1 ? "{$installments} taksit" : null];
        }

        $lines = array_map(function ($l) {
            $l['amount'] = round((float) $l['amount'], 2);

            return $l;
        }, $lines);

        return [
            'products' => $products,
            'discount' => round($gift + $points + $campaign, 2),
            'shipping' => $shipping,
            'surcharge' => $surcharge,
            'lines' => $lines,
        ];
    }

    /**
     * metadata.discounts.campaign_split (sync'te kalemlerden hesaplanır) → ayrı satırlar:
     * "1 Alana 1 Bedava" gibi ürün kampanyaları kendi satırında, sepet tutarı kademeleri (%5/%10…)
     * tek "Sepet indirimi" satırında + etkin oran. Bölüşüm toplamı Ticimax'ın kampanya toplamını
     * 1 TL içinde tutmuyorsa null (tek satırlı eski görünüm).
     */
    private function campaignSplitLines(array $meta, float $campaign, float $products): ?array
    {
        $split = $meta['campaign_split'] ?? [];
        if (! is_array($split) || $split === []) {
            return null;
        }
        $sum = array_sum(array_map(fn ($g) => (float) ($g['amount'] ?? 0), $split));
        if (abs($sum - $campaign) > 1) {
            return null;
        }
        $names = collect($meta['campaigns'] ?? [])->keyBy('id');
        $nameOf = fn (array $ids) => collect($ids)->map(fn ($id) => $names[$id]['name'] ?? null)->filter()->implode(' + ');

        // Ürün kampanyaları önce, kademe indirimi sonra (Ticimax da kademeyi kampanya sonrası tutara uygular)
        usort($split, fn ($a, $b) => ($a['kind'] === 'tier') <=> ($b['kind'] === 'tier'));
        $productCampaigns = array_sum(array_map(fn ($g) => $g['kind'] === 'campaign' ? (float) $g['amount'] : 0, $split));

        $lines = [];
        foreach ($split as $g) {
            $amount = (float) $g['amount'];
            $items = (int) ($g['items'] ?? 0);
            if ($g['kind'] === 'campaign') {
                $detail = $items > 0 ? "{$items} üründe" : null;
                $free = isset($g['free']) ? (int) $g['free'] : (! empty($g['ratio']) ? (int) floor($items * (float) $g['ratio'] + 1e-9) : 0);
                if ($items > 0 && $free >= 1) {
                    $detail = "{$items} ürün · en ucuz {$free} adedi bedava";
                }
                $lines[] = ['type' => 'campaign_product', 'label' => $nameOf($g['ids']) ?: 'Ürün kampanyası', 'amount' => -$amount, 'detail' => $detail];
            } elseif ($g['kind'] === 'tier') {
                $base = $products - $productCampaigns;
                $lines[] = $this->tierLine($g['ids'], $names, $amount, $base);
            } else {
                $lines[] = ['type' => 'campaign', 'label' => 'Kampanya indirimi', 'amount' => -$amount, 'detail' => $nameOf($g['ids']) ?: null];
            }
        }
        // Kuruş farkı (yuvarlama) en büyük satıra eklenir → satırlar toplamla birebir
        $diff = round($campaign - $sum, 2);
        if (abs($diff) >= 0.01) {
            $i = 0;
            $big = 0;
            foreach ($lines as $k => $l) {
                if (-$l['amount'] > $big) {
                    $big = -$l['amount'];
                    $i = $k;
                }
            }
            $lines[$i]['amount'] -= $diff;
        }

        return $lines;
    }

    /**
     * Sepet tutarı kademesi satırı. Kademe adından eşik + yüzde okunur ("5.000 TL üzerine ek %10").
     * Hak edilen kademe = ürün kampanyaları (1+1) düşüldükten sonraki sepet tutarının geçtiği en
     * yüksek eşik. Uygulanan oran onu tutuyorsa "Sepet indirimi %10 · 5.000 TL üzeri kademesi";
     * tutmuyorsa (Ticimax kademeleri üst üste uyguladı ya da eksik uyguladı) beklenen tutar ve
     * farkla birlikte 'warn' satırı.
     */
    private function tierLine(array $ids, Collection $names, float $amount, float $base): array
    {
        $tiers = [];
        foreach ($ids as $id) {
            $name = (string) ($names[$id]['name'] ?? '');
            if (preg_match('/%\s*(\d+(?:[.,]\d+)?)/u', $name, $pm)) {
                $threshold = preg_match('/([\d.]+)\s*TL/iu', $name, $tm) ? (float) str_replace('.', '', $tm[1]) : 0.0;
                $tiers[] = ['pct' => (float) str_replace(',', '.', $pm[1]), 'threshold' => $threshold];
            }
        }
        usort($tiers, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        $line = ['type' => 'cart_campaign', 'label' => 'Sepet indirimi', 'amount' => -$amount, 'detail' => null];
        if (! $tiers || $base <= 0) {
            return $line;
        }
        $pctText = fn (float $p) => '%' . rtrim(rtrim(number_format($p, 1, ',', ''), '0'), ',');
        $tl = fn (float $v) => number_format($v, 2, ',', '.');
        $effective = $amount / $base * 100;

        $expected = collect($tiers)->first(fn ($t) => $t['threshold'] <= $base + 0.01) ?? $tiers[0];
        $expectedAmount = round($base * $expected['pct'] / 100, 2);
        $tierName = $expected['threshold'] > 0 ? number_format($expected['threshold'], 0, ',', '.') . ' TL üzeri' : null;

        if (abs($effective - $expected['pct']) < 0.3) {
            $line['label'] .= ' ' . $pctText($expected['pct']);
            $line['detail'] = $tierName ? "{$tierName} kademesi" : null;

            return $line;
        }

        $stacked = 100 * (1 - array_reduce($tiers, fn ($c, $t) => $c * (1 - $t['pct'] / 100), 1.0));
        $diff = round($amount - $expectedAmount, 2);
        $parts = ['Sepet ' . number_format($base, 0, ',', '.') . ' TL → ' . $pctText($expected['pct']) . ' kademesi, beklenen −' . $tl($expectedAmount)];
        if (count($tiers) > 1 && abs($stacked - $effective) < 0.3) {
            $line['label'] .= ' ' . collect($tiers)->map(fn ($t) => $pctText($t['pct']))->implode(' + ');
            $parts[] = 'kademeler üst üste uygulanmış';
        } else {
            $line['label'] .= ' ≈' . $pctText($effective);
        }
        $parts[] = $tl(abs($diff)) . ' TL ' . ($diff > 0 ? 'fazla' : 'eksik') . ' indirim';
        $line['detail'] = implode(' · ', $parts);
        $line['warn'] = true;

        return $line;
    }

    /** @return array{0:string,1:string} [type, label] */
    private function couponType(?string $code, array $wheelCodes, array $appCodes): array
    {
        $u = mb_strtoupper(trim((string) $code));
        if ($u === '') {
            return ['coupon', 'Hediye çeki'];
        }
        if (isset($wheelCodes[$u])) {
            return ['coupon_wheel', 'Çark kuponu'];
        }
        if (isset($appCodes[$u]) || str_starts_with($u, 'APP')) {
            return ['coupon_app', 'Uygulama kuponu'];
        }
        if (str_starts_with($u, 'SEPET-')) {
            return ['coupon_cart_recovery', 'Sepet kurtarma kuponu'];
        }
        if (str_starts_with($u, 'SMS')) {
            return ['coupon_sms', 'SMS kuponu'];
        }
        if (str_starts_with($u, 'WA') || str_starts_with($u, 'WP')) {
            return ['coupon_wa', 'WhatsApp kuponu'];
        }
        if (str_starts_with($u, 'MAIL') || str_starts_with($u, 'EMAIL')) {
            return ['coupon_email', 'E-posta kuponu'];
        }

        return ['coupon', 'Kupon'];
    }
}
