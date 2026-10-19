<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\OrderDelayService;
use App\Services\TicimaxService;
use Illuminate\Support\Facades\Log;

/**
 * Açık (kargoya verilmemiş) siparişleri Ticimax'tan TEK TEK, ID ile tazeler.
 *
 * Neden: crm:sync-orders-live --mode=status "son 7 günde düzenlenen" siparişleri
 * ID DESC tarar ama Ticimax'ta 30 dakikada ~2.000 sipariş "düzenlenmiş" görünüyor;
 * 120 sn limitiyle yalnız en yeni ~3.800 sipariş tazelenebiliyor. Birkaç gün önce
 * verilmiş bir sipariş bugün kargoya verilse/iptal edilse ertesi sabahki safety
 * taramasına kadar bizde eski statüde kalıyordu → Gecikmeler sayfası şişiyordu
 * (6 Eki: 1.167 gecikmenin 322'si Ticimax'ta çoktan kargoda/iptaldi).
 *
 * Küme küçük (birkaç bin) ve ID sorgusu güvenilir; Ticimax ara sıra rastgele
 * "Tedarikçiye bağlı siparişler bulunamadı" döndürdüğü için boş cevapta yeniden dener.
 *
 * Ticimax'ta SİLİNEN sipariş de aynı "bulunamadı" cevabını verir. Rastgele boş
 * cevaptan ayırmak için art arda MISSING_RUNS çalıştırmada hiç alınamayan sipariş
 * metadata.ticimax_deleted=true ile işaretlenir → Gecikmeler'de görünmez.
 * Tekrar bulunursa işaret kalkar.
 */
class RefreshOpenCrmOrders extends SyncCrmOrders
{
    protected $signature = 'crm:refresh-open-orders
                            {--channel= : Belirli kanal ID}
                            {--days= : Geriye bakış (gün); varsayılan tenant delay_lookback_days}
                            {--scope=open : open (hazırlıktaki siparişler) | moving (kargoda/mağazada/iade sürecindeki — gece mutabakatı)}';

    protected $description = 'Kargoya verilmemiş açık siparişlerin statüsünü Ticimax\'tan ID ile tazeler (Gecikmeler)';

    private const ATTEMPTS = 3;

    private const MISSING_RUNS = 2;

    /** --scope=moving: hâlâ hareket görebilecek statüler (kargoda, mağaza, teslim edilemedi, iade süreci) */
    private const MOVING_STATUSES = [6, 11, 12, 16, 18, 19, 20, 21];

    public function handle(): int
    {
        $query = Channel::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('type', 'like', 'ticimax%')
            ->where('type', 'like', '%-ecommerce');

        if ($channelId = $this->option('channel')) {
            $query->where('id', (int) $channelId);
        }

        foreach ($query->get() as $channel) {
            $this->refreshChannel($channel);
        }

        return 0;
    }

    protected function refreshChannel(Channel $channel): void
    {
        $service = TicimaxService::fromChannel($channel);
        if (! $service->isAvailable()) {
            $this->warn("Kanal #{$channel->id}: Ticimax servisi kullanilamiyor");

            return;
        }

        $this->tenantId = $channel->tenant_id;
        $this->channelId = $channel->id;
        $this->totalNew = $this->totalUpdated = 0;

        $moving = $this->option('scope') === 'moving';
        $settings = Tenant::find($channel->tenant_id)?->settings ?? [];
        $days = (int) ($this->option('days') ?: ($moving ? 90 : ($settings['delay_lookback_days'] ?? OrderDelayService::DEFAULT_LOOKBACK_DAYS)));
        $days = max(7, min(365, $days)) + 1;

        $query = Order::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->where('order_date', '>=', now()->subDays($days));
        if ($moving) {
            // Gece mutabakatı: canlı "changes" taramasının kaçırabileceği (Ticimax'ın boş
            // döndüğü anlar vb.) hareketli siparişler. Teslim/iptal/iade ödendi gibi kapanmış
            // statüler ve 90 günden eskiler taranmaz (hareket görmez).
            $query->where('payment_status', 1)->whereIn('status', self::MOVING_STATUSES);
        } else {
            // OrderDelayService::delayedOrders ile aynı aday küme (+1 gün pay)
            $query->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('status', 0)->where('paid_amount', '>', 0);
                })->orWhereIn('status', array_values(array_diff(OrderDelayService::PREP_STATUSES, [0])));
            });
        }
        $orders = $query->orderByDesc('id')->get(['id', 'platform_order_id', 'status', 'metadata']);

        $start = microtime(true);
        $changed = 0;
        $missing = 0;
        $deleted = 0;

        foreach ($orders as $o) {
            $data = null;
            for ($i = 0; $i < self::ATTEMPTS && $data === null; $i++) {
                try {
                    $r = $service->getOrders(['SiparisID' => (int) $o->platform_order_id, 'OdemeGetir' => true], 1, 1, true);
                    $w = $r['data']['SelectSiparisResult']['WebSiparis'] ?? null;
                    if ($w && ! isset($w['ID'])) {
                        $w = $w[0] ?? null;
                    }
                    if ($w && (string) ($w['ID'] ?? '') === (string) $o->platform_order_id) {
                        $data = $w;
                    }
                } catch (\Throwable $e) {
                    // geçici hata → yeniden dene
                }
                if ($data === null) {
                    usleep(300000);
                }
            }

            $meta = $o->metadata ?? [];

            if ($data === null && $moving) {
                $missing++;

                continue;
            }

            if ($data === null) {
                $missing++;
                $meta['ticimax_missing_runs'] = (int) ($meta['ticimax_missing_runs'] ?? 0) + 1;
                if ($meta['ticimax_missing_runs'] >= self::MISSING_RUNS && empty($meta['ticimax_deleted'])) {
                    $meta['ticimax_deleted'] = true;
                    $meta['ticimax_deleted_at'] = now()->toIso8601String();
                    $deleted++;
                }
                Order::withoutGlobalScopes()->whereKey($o->id)->update(['metadata' => json_encode($meta)]);

                continue;
            }

            if (isset($meta['ticimax_missing_runs']) || isset($meta['ticimax_deleted'])) {
                unset($meta['ticimax_missing_runs'], $meta['ticimax_deleted'], $meta['ticimax_deleted_at']);
                Order::withoutGlobalScopes()->whereKey($o->id)->update(['metadata' => $meta === [] ? null : json_encode($meta)]);
            }

            if ((int) ($data['Durum'] ?? 0) !== (int) $o->status) {
                $changed++;
            }
            $this->syncOrder($data, $channel);
        }

        $this->recalculateCustomerTotals($channel);

        $elapsed = round(microtime(true) - $start, 1);
        $this->info("Kanal #{$channel->id}: {$orders->count()} " . ($moving ? 'hareketli' : 'acik') . " siparis, {$changed} statu degisti, {$missing} alinamadi, {$deleted} silinmis isaretlendi ({$elapsed}sn)");
        Log::info('RefreshOpenCrmOrders: completed', [
            'channel' => $channel->id,
            'open' => $orders->count(),
            'changed' => $changed,
            'missing' => $missing,
            'marked_deleted' => $deleted,
            'duration' => $elapsed,
        ]);
    }
}
