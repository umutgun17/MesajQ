<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDelayReview;
use App\Models\Tenant;
use App\Services\Chat\ChatShipping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CRM › Gecikmeler — hangi sipariş, hangi aşamada, kaç gün gecikti?
 *
 * Gecikme YALNIZ sipariş statüsünden yönetilir (kullanıcı kararı 5 Eki):
 * ödemesi alınmış sipariş, söz verilen kargo gününün sonunda hâlâ kargoya
 * verilmemiş statüdeyse (Siparişiniz Alındı / Onay / Paketleniyor / Tedarik
 * Ediliyor) gecikmededir. "Kargoya Verildi" ve sonrası mağazadan çıkmış
 * sayılır, gecikme DEĞİLDİR. Söz verilen gün, ürün detaydaki "Bugün Kargoda"
 * rozetiyle AYNI kesim takviminden (ChatShipping) hesaplanır.
 *
 * Aday küme küçük (son N günün açık siparişleri, birkaç bin) — hesap PHP'de,
 * süzme/sıralama/sayfalama bellekte yapılır.
 */
class OrderDelayService
{
    /** Kargoya verilmeden önceki açık durumlar (3 = Ödeme Bekliyor hariç). */
    public const PREP_STATUSES = [0, 1, 2, 4, 5];

    public const DEFAULT_LOOKBACK_DAYS = 45;

    protected array $schedule;

    protected int $lookbackDays;

    protected CarbonImmutable $now;

    public function __construct(protected Tenant $tenant, ?CarbonImmutable $now = null)
    {
        $s = $tenant->settings ?? [];
        $this->schedule = ChatShipping::schedule($tenant->id);
        $this->lookbackDays = max(7, min(365, (int) ($s['delay_lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS)));
        $this->now = $now ?? CarbonImmutable::now(ChatShipping::TZ);
    }

    public function config(): array
    {
        return [
            'lookback_days' => $this->lookbackDays,
            'has_schedule' => $this->schedule !== [],
        ];
    }

    /**
     * Geciken siparişlerin tamamı (inceleme kaydıyla birlikte), süzülmemiş.
     *
     * @return Collection<int, array>
     */
    public function delayedOrders(): Collection
    {
        $since = $this->now->subDays($this->lookbackDays)->format('Y-m-d H:i:s');

        $orders = Order::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('order_date', '>=', $since)
            // Ticimax'ta silinmiş siparişler (crm:refresh-open-orders işaretler)
            ->whereRaw("COALESCE((metadata->>'ticimax_deleted')::boolean, false) = false")
            ->where(function ($q) {
                $q->where(function ($q) {
                    // Durum 0'da payment_status=1 ancak gerçek ödeme varsa (paid_amount>0)
                    $q->where('status', 0)->where('paid_amount', '>', 0);
                })
                    ->orWhereIn('status', array_values(array_diff(self::PREP_STATUSES, [0])));
            })
            ->get([
                'id', 'channel_id', 'customer_id', 'platform_order_id', 'order_no', 'status', 'status_label',
                'total_amount', 'paid_amount', 'customer_name', 'customer_email', 'shipping_address',
                'cargo_company', 'cargo_tracking_no', 'cargo_tracking_url', 'order_source', 'items_count',
                'order_date', 'status_updated_at', 'platform_updated_at', 'updated_at', 'payments',
            ]);

        $rows = collect();
        foreach ($orders as $o) {
            $calc = $this->evaluate($o);
            if ($calc !== null) {
                $rows->push($calc + ['order' => $o]);
            }
        }

        if ($rows->isEmpty()) {
            return $rows;
        }

        $reviews = OrderDelayReview::query()
            ->with('assignee:id,name')
            ->whereIn('order_id', $rows->pluck('order.id'))
            ->get()
            ->keyBy('order_id');

        return $rows->map(function ($r) use ($reviews) {
            $r['review'] = $reviews->get($r['order']->id);

            return $r;
        });
    }

    /**
     * Tek siparişin gecikme hesabı; gecikmemişse null.
     *
     * @return array{stage: string, due_date: string, delay_days: int, age_days: int}|null
     */
    public function evaluate(Order $o): ?array
    {
        $t = $this->timeline($o);

        return $t !== null && $t['delay_days'] > 0 ? $t : null;
    }

    /**
     * Kargoya verilmemiş siparişin söz verilen günü ve
     * gecikmesi. delay_days > 0 → gecikmede; ≤ 0 → kalan gün (0 = bugün son gün).
     * Açık olmayan durumlar için null.
     *
     * @return array{stage: string, due_date: string, delay_days: int, age_days: int}|null
     */
    public function timeline(Order $o): ?array
    {
        $status = (int) $o->status;
        $isPrep = in_array($status, self::PREP_STATUSES, true) && ($status !== 0 || (float) $o->paid_amount > 0);
        if (! $isPrep) {
            return null;
        }

        // Ticimax tarihleri TR yerel saatiyle, saat dilimsiz saklanır → ham değer TR olarak okunur
        $raw = fn (string $k) => $o->getRawOriginal($k);
        if (! $raw('order_date')) {
            return null;
        }
        $orderDate = CarbonImmutable::parse($raw('order_date'), ChatShipping::TZ);
        $today = $this->now->startOfDay();

        $ns = ChatShipping::nextShipFor($this->schedule, $orderDate);
        $due = $ns['ship'] ?? $this->addWorkDays($orderDate->startOfDay(), 1);

        // Söz verilen günün SONUNA kadar gecikme sayılmaz
        return [
            'stage' => 'prep',
            'due_date' => $due->format('Y-m-d'),
            'delay_days' => (int) round($due->diffInDays($today, false)),
            'age_days' => (int) round($orderDate->startOfDay()->diffInDays($today, false)),
        ];
    }

    /** Pazar günlerini atlayarak gün ekler (kargo Pazar dağıtım yapmaz). */
    protected function addWorkDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $d = $from;
        while ($days > 0) {
            $d = $d->addDay();
            if ($d->isoWeekday() !== 7) {
                $days--;
            }
        }

        return $d;
    }

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 1 => '1',
            $days <= 3 => '2-3',
            $days <= 7 => '4-7',
            default => '8+',
        };
    }
}
