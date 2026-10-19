<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Order;
use App\Services\TicimaxService;
use Illuminate\Support\Facades\Log;

class SyncCrmOrdersLive extends SyncCrmOrders
{
    protected $signature = 'crm:sync-orders-live
                            {--mode=new : Sync modu: new (yeni siparisler), changes (Ticimaxta degisen TUM siparisler, imlecli), status (durum guncelleme), safety (30 gun guvenlik)}
                            {--channel= : Belirli kanal ID}
                            {--field=duzenleme : changes modu: duzenleme (her duzenleme) | durum (statu degisim tarihi; Ticimaxta yavas)}';

    protected $description = 'Canli siparis senkronizasyonu (3 katmanli: new + status + safety)';

    /** Ardisik bos ID sonrasi dur (new mode) */
    private const MAX_CONSECUTIVE_EMPTY = 50;

    /** Pagination guvenlik limiti */
    private const MAX_PAGES = 500;

    /** Status mode: her calisma max sure (saniye) */
    private const STATUS_TIME_LIMIT = 120;

    /** Status mode: tarama penceresi (gun) */
    private const STATUS_DAYS = 7;

    /** New mode: bulunan ID'ler arasında alınamayan (Ticimax rastgele boş/hata) ID'ler kaç koşu yeniden denenir */
    private const PENDING_MAX_TRIES = 30;

    /** New mode: ödemesi henüz görünmeyen yeni kayıtlar kaç dakika boyunca her koşuda tazelenir */
    private const UNPAID_RECHECK_MINUTES = 60;

    private const UNPAID_RECHECK_LIMIT = 120;

    /** Changes mode: imleç geriye örtüşme (dk) ve en fazla geriye gidiş (saat) */
    private const CHANGES_OVERLAP_MINUTES = 3;

    private const CHANGES_MAX_BACK_HOURS = 12;

    private const CHANGES_PAGE_SIZE = 50;

    public function handle(): int
    {
        // Bu komut Ticimax'a özgüdür (int sipariş-ID yürüyüşü); ikas gibi diğer
        // e-ticaret kanalları kendi sync komutlarını kullanır — ->first() onları
        // kapmasın diye platform filtresi şart.
        $query = Channel::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('type', 'like', 'ticimax%')
            ->where('type', 'like', '%-ecommerce');

        if ($channelId = $this->option('channel')) {
            $query->where('id', (int) $channelId);
        }

        $channel = $query->first();

        if (! $channel) {
            $this->error('Aktif e-ticaret kanali bulunamadi.');

            return 1;
        }

        $service = TicimaxService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->error('Ticimax servisi kullanilamiyor.');

            return 1;
        }

        $this->tenantId = $channel->tenant_id;
        $this->channelId = $channel->id;

        $mode = $this->option('mode');

        return match ($mode) {
            'new' => $this->syncNewOrders($service, $channel),
            'changes' => $this->syncChanges($service, $channel),
            'status' => $this->syncStatus($service, $channel),
            'safety' => $this->syncSafety($service, $channel),
            default => $this->invalidMode($mode),
        };
    }

    /**
     * Katman 1: Yeni siparişleri ID bazlı çek.
     * Son bilinen ID'den ileriye doğru tarar, ardışık 50 boş ID'de durur.
     */
    protected function syncNewOrders(TicimaxService $service, Channel $channel): int
    {
        $settings = $channel->settings ?? [];
        $lastId = (int) ($settings['last_live_order_id'] ?? 0);

        if ($lastId === 0) {
            $lastId = (int) Order::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->selectRaw('MAX(platform_order_id::int) as max_id')
                ->value('max_id');
        }

        if ($lastId === 0) {
            $this->error('Son siparis ID bulunamadi. Once full sync yapin.');

            return 1;
        }

        $startId = $lastId + 1;
        $found = 0;
        $empty = 0;
        $errors = 0;
        $consecutiveEmpty = 0;
        $lastFoundId = $lastId;
        $failedIds = [];
        $startTime = microtime(true);

        $this->info("[new] ID {$startId}'den baslaniyor (onceki: {$lastId})...");

        for ($id = $startId; ; $id++) {
            try {
                $result = $service->getOrders(['SiparisID' => $id, 'OdemeGetir' => true], 1, 1);

                if (! $result['success']) {
                    $errors++;
                    $consecutiveEmpty++;
                    $failedIds[] = $id;

                    if ($consecutiveEmpty >= self::MAX_CONSECUTIVE_EMPTY) {
                        break;
                    }

                    continue;
                }

                $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];

                if (! empty($orders) && isset($orders['ID'])) {
                    $orders = [$orders];
                }

                if (empty($orders)) {
                    $empty++;
                    $consecutiveEmpty++;
                    $failedIds[] = $id;

                    if ($consecutiveEmpty >= self::MAX_CONSECUTIVE_EMPTY) {
                        break;
                    }
                } else {
                    $this->syncOrder($orders[0], $channel);
                    $found++;
                    $lastFoundId = $id;
                    $consecutiveEmpty = 0;
                }
            } catch (\Exception $e) {
                $errors++;
                $consecutiveEmpty++;
                $failedIds[] = $id;

                if ($errors <= 3) {
                    Log::warning("SyncCrmOrdersLive[new]: ID {$id} exception", [
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($consecutiveEmpty >= self::MAX_CONSECUTIVE_EMPTY) {
                    break;
                }
            }
        }

        $newLastId = max($lastFoundId, $id - self::MAX_CONSECUTIVE_EMPTY);
        $settings = $channel->fresh()->settings ?? [];

        // Bulunan son ID'nin GERİSİNDE kalıp alınamayan ID'ler: Ticimax ara sıra var olan
        // siparişe de boş/hata döner. Eskiden bu ID'ler bir daha denenmiyor, sipariş ancak
        // status/safety taramasına (kalemsiz ya da ertesi sabah) kalıyordu → bekleme listesi.
        $pending = $settings['live_pending_ids'] ?? [];
        foreach ($failedIds as $fid) {
            if ($fid < $lastFoundId && ! isset($pending[(string) $fid])) {
                $pending[(string) $fid] = 0;
            }
        }
        [$pending, $recovered] = $this->retryPendingIds($service, $channel, $pending);
        $found += $recovered;

        $settings['last_live_order_id'] = $newLastId;
        $settings['live_pending_ids'] = $pending;
        $settings['last_new_sync_at'] = now()->toIso8601String();
        $channel->update(['settings' => $settings]);

        // Ödeme adımındaki yeni kayıtlar: kayıt ilk çekildiğinde ödeme henüz düşmemiş
        // olabilir; ödemeli filtreli panelde siparişin görünmesi status taramasını (15 dk)
        // beklemesin.
        $paidNow = $this->recheckRecentUnpaid($service, $channel);

        $this->recalculateCustomerTotals($channel);

        $elapsed = round(microtime(true) - $startTime, 1);
        $scanned = $id - $startId;

        $this->info("[new] Tamamlandi: {$found} yeni ({$recovered} bekleyen kurtarildi, " . count($pending) . " bekliyor), {$paidNow} odeme onayi, {$empty} bos, {$errors} hata | Taranan: {$scanned} ID, son: {$newLastId} ({$elapsed}sn)");

        if ($found > 0) {
            Log::info('SyncCrmOrdersLive[new]: completed', [
                'found' => $found,
                'last_id' => $newLastId,
                'duration' => $elapsed,
            ]);
        }

        return 0;
    }

    /** Tek siparişi ID ile çeker; bulunamazsa/hata olursa null. */
    private function fetchById(TicimaxService $service, int $id): ?array
    {
        try {
            $r = $service->getOrders(['SiparisID' => $id, 'OdemeGetir' => true], 1, 1);
        } catch (\Throwable $e) {
            return null;
        }
        $w = $r['data']['SelectSiparisResult']['WebSiparis'] ?? null;
        if ($w && ! isset($w['ID'])) {
            $w = $w[0] ?? null;
        }

        return ($w && (int) ($w['ID'] ?? 0) === $id) ? $w : null;
    }

    /**
     * @param  array<string,int>  $pending  id => deneme sayısı
     * @return array{0: array<string,int>, 1: int}
     */
    private function retryPendingIds(TicimaxService $service, Channel $channel, array $pending): array
    {
        $recovered = 0;
        foreach ($pending as $pid => $tries) {
            $exists = Order::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('platform_order_id', (string) $pid)
                ->exists();
            if ($exists) {
                unset($pending[$pid]);

                continue;
            }
            $data = $this->fetchById($service, (int) $pid);
            if ($data) {
                $this->syncOrder($data, $channel);
                $recovered++;
                unset($pending[$pid]);
            } elseif ($tries + 1 >= self::PENDING_MAX_TRIES) {
                // Ticimax'ta gerçekten yok (silinmiş/hiç oluşmamış ID) — bırak
                unset($pending[$pid]);
            } else {
                $pending[$pid] = $tries + 1;
            }
        }

        return [$pending, $recovered];
    }

    /** Son N dakikadaki ödemesiz kayıtları ID ile tazeler; ödemesi düşenlerin sayısını döner. */
    private function recheckRecentUnpaid(TicimaxService $service, Channel $channel): int
    {
        $rows = Order::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->where('payment_status', 0)
            ->where('created_at', '>=', now()->subMinutes(self::UNPAID_RECHECK_MINUTES))
            ->orderByDesc('id')
            ->limit(self::UNPAID_RECHECK_LIMIT)
            ->pluck('platform_order_id');

        $paid = 0;
        foreach ($rows as $pid) {
            $data = $this->fetchById($service, (int) $pid);
            if (! $data) {
                continue;
            }
            $this->syncOrder($data, $channel);
            if (((float) ($data['OdenenTutar'] ?? 0)) > 0 || ((int) ($data['Durum'] ?? 0)) > 0) {
                $paid++;
            }
        }

        return $paid;
    }

    /**
     * Katman 1b: Ticimax'ta DEĞİŞEN tüm siparişler — sipariş tarihi kaç ay önce olursa olsun.
     *
     * Ticimax'ta statüyü panelden elle, kargo/ERP/pazaryeri web servisleri de otomatik
     * değiştiriyor; hepsi siparişin DuzenlemeTarihi'ni günceller. İmleç (TR yerel saat,
     * Ticimax'ın beklediği biçim) son başarılı taramanın başlangıcıdır; her koşu
     * [imleç − örtüşme, şimdi] aralığını ID DESC sayfalayıp bitirir. Aralık taranırken
     * yeni düzenlenen sipariş kümeye eklenir, sayfalar yalnız aşağı kayar → atlama olmaz.
     *
     * --field=durum (varsayılan, 2 dk'da bir): DurumTarihi = statü değişim tarihi → yalnız
     * statüsü değişenler (~5/dk). --field=duzenleme her düzenlemeyi getirir ama Ticimax'ta
     * bir entegrasyon teslim edilmiş siparişlere dakikada yüzlerce kez dokunuyor (7 Eki:
     * 6 dk'da 2.400) → yalnız elle/teşhis için.
     *
     * DİKKAT (7 Eki 2026 ölçüldü): Ticimax tarih filtreli SelectSiparis'te UrunGetir=false
     * verilirse HER ZAMAN boş liste döner — ürünlerle (true) çekmek şart. Sayfa 200'de
     * zaman aşımına düşüyor, 50 güvenli (~1-3 sn/sayfa).
     */
    protected function syncChanges(TicimaxService $service, Channel $channel): int
    {
        $tz = 'Europe/Istanbul';
        $field = $this->option('field') === 'durum' ? 'DurumTarihi' : 'DuzenlemeTarihi';
        $cursorKey = $field === 'DurumTarihi' ? 'changes_cursor_durum' : 'changes_cursor';
        $runStart = now($tz);
        $settings = $channel->settings ?? [];
        $cursor = isset($settings[$cursorKey])
            ? \Illuminate\Support\Carbon::parse($settings[$cursorKey], $tz)
            : $runStart->copy()->subMinutes(30);
        $floor = $runStart->copy()->subHours(self::CHANGES_MAX_BACK_HOURS);
        if ($cursor->lt($floor)) {
            Log::warning('SyncCrmOrdersLive[changes]: imlec cok eski, kirpildi', ['cursor' => $cursor->toDateTimeString()]);
            $cursor = $floor;
        }
        $since = $cursor->copy()->subMinutes(self::CHANGES_OVERLAP_MINUTES)->format('Y-m-d\TH:i:s');

        $filters = [$field . 'Bas' => $since, 'OdemeGetir' => true];
        $startTime = microtime(true);
        $seen = [];
        $statusChanged = 0;
        $complete = false;
        $apiSec = 0.0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $orders = null;
            for ($try = 0; $try < 3 && $orders === null; $try++) {
                if ($try > 0) {
                    sleep(2);
                }
                $t0 = microtime(true);
                $r = $service->getOrders($filters, $page, self::CHANGES_PAGE_SIZE, true, 'DESC');
                $apiSec += microtime(true) - $t0;
                if (! $r['success']) {
                    continue;
                }
                $w = $r['data']['SelectSiparisResult']['WebSiparis'] ?? [];
                if (! empty($w) && isset($w['ID'])) {
                    $w = [$w];
                }
                // İlk sayfa boşsa (gündüz neredeyse imkânsız) bir kez daha sor: Ticimax ara sıra boş döner
                if (empty($w) && $page === 1 && $try === 0) {
                    continue;
                }
                $orders = $w;
            }

            if ($orders === null) {
                $this->error("[changes] Sayfa {$page} alinamadi — imlec ilerletilmedi, sonraki kosu ayni araligi tarar.");
                Log::warning('SyncCrmOrdersLive[changes]: page failed', ['page' => $page, 'since' => $since]);
                break;
            }

            if ($orders !== []) {
                $ids = array_map(fn ($o) => (string) ($o['ID'] ?? ''), $orders);
                $local = Order::withoutGlobalScopes()
                    ->where('channel_id', $channel->id)
                    ->whereIn('platform_order_id', $ids)
                    ->get(['id', 'platform_order_id', 'status'])
                    ->keyBy('platform_order_id');
                $itemSigs = $this->localItemSignatures($local->pluck('id')->all());
                foreach ($orders as $o) {
                    $pid = (string) ($o['ID'] ?? '');
                    if ($pid === '' || isset($seen[$pid])) {
                        continue;
                    }
                    $seen[$pid] = true;
                    $l = $local[$pid] ?? null;
                    if ($l && (int) $l->status !== (int) ($o['Durum'] ?? 0)) {
                        $statusChanged++;
                    }
                    // Kalemler aynıysa yeniden yazma (dakikada yüzlerce düzenleme geliyor)
                    if ($l && ($itemSigs[$l->id] ?? null) === $this->remoteItemSignature($o)) {
                        unset($o['Urunler']);
                    }
                    $this->syncOrder($o, $channel);
                }
            }

            if (count($orders) < self::CHANGES_PAGE_SIZE) {
                $complete = true;
                break;
            }
            usleep(200000);
        }

        $settings = $channel->fresh()->settings ?? [];
        if ($complete) {
            $settings[$cursorKey] = $runStart->format('Y-m-d H:i:s');
        }
        $settings['last_changes_sync_at'] = now()->toIso8601String();
        $channel->update(['settings' => $settings]);

        $this->recalculateCustomerTotals($channel);

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("[changes:{$field}] >= {$since}: " . count($seen) . " degisen siparis, {$statusChanged} statu degisti ({$this->totalNew} yeni)" . ($complete ? '' : ' [YARIM]') . " ({$elapsed}sn, Ticimax " . round($apiSec, 1) . "sn)");

        return 0;
    }

    /** @return array<int,string> order_id => kalem imzası */
    private function localItemSignatures(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = \Illuminate\Support\Facades\DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get(['order_id', 'platform_variant_id', 'quantity', 'status', 'status_label', 'unit_price']);
        $sig = [];
        foreach ($rows as $r) {
            $sig[$r->order_id][] = implode(':', [(string) $r->platform_variant_id, (int) $r->quantity, (int) $r->status, (string) $r->status_label, number_format((float) $r->unit_price, 2, '.', '')]);
        }

        return array_map(fn ($a) => implode('|', $a), $sig);
    }

    private function remoteItemSignature(array $o): string
    {
        $parts = [];
        foreach ($this->extractItems($o) as $it) {
            $label = trim($it['IslemAd'] ?? '') ?: (trim($it['DurumAd'] ?? '') ?: '');
            $price = round((float) ($it['Tutar'] ?? 0) + (float) ($it['KdvTutari'] ?? 0), 2);
            $parts[] = implode(':', [(string) ($it['UrunID'] ?? ''), (int) ($it['Adet'] ?? 1), (int) ($it['Durum'] ?? 0), $label, number_format($price, 2, '.', '')]);
        }

        return implode('|', $parts);
    }

    /**
     * Katman 2: Durum güncellemeleri — zaman limitli sayfalama.
     *
     * Son 7 günü sürekli döngüsel tarar. Her çalışmada kaldığı sayfadan devam eder,
     * max 2 dakika çalışır, pozisyonu kaydeder. Tüm sayfalar bitince döngüyü sıfırlar.
     *
     * Ürün kalemleri işlenmez (sadece statü/kargo/ödeme güncellenir).
     */
    protected function syncStatus(TicimaxService $service, Channel $channel): int
    {
        // Son 7 günde DEĞİŞEN siparişleri EN YENİ önce (ID DESC) tazele: her koşu sayfa 1'den
        // başlar, zaman limitine kadar en yeni ~binlerce değişikliği günceller. Böylece yeni
        // ödeme onayları/kargo çıkışları ~15 dk içinde güncel olur (eski ASC sıralama + resume
        // cursor'ında yeni siparişler en sona kalıp saatlerce/günlerce bayat kalıyordu → arama
        // atıf hayaletlerinin ve panelde bayat statülerin kökü buydu). Uzun kuyruk gecelik
        // safety taramasında toplanır.
        $since = now()->subDays(self::STATUS_DAYS)->format('Y-m-d\TH:i:s');
        $page = 1;
        $this->info("[status] Son " . self::STATUS_DAYS . " gun, en yeni once (>= {$since})...");

        $filters = [
            'DuzenlemeTarihiBas' => $since,
            'OdemeGetir' => true,
        ];

        $processed = 0;
        $startTime = microtime(true);
        $cycleCompleted = false;

        while ($page <= self::MAX_PAGES) {
            // Zaman limiti kontrolü
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= self::STATUS_TIME_LIMIT) {
                $this->info("[status] Zaman limiti (" . self::STATUS_TIME_LIMIT . "sn), sayfa {$page}'de duraklatildi.");
                break;
            }

            $result = $service->getOrders($filters, $page, 200, true, 'DESC');

            if (! $result['success']) {
                $this->error("[status] Sayfa {$page}: API hatasi — " . ($result['error'] ?? 'Bilinmeyen'));

                sleep(5);
                $retryResult = $service->getOrders($filters, $page, 200, true, 'DESC');

                if (! $retryResult['success']) {
                    $this->error("[status] Sayfa {$page}: Retry basarisiz, duraklatiliyor.");
                    Log::error('SyncCrmOrdersLive[status]: API error', [
                        'page' => $page,
                        'error' => $retryResult['error'] ?? 'unknown',
                    ]);

                    break;
                }

                $result = $retryResult;
            }

            $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];

            if (! empty($orders) && isset($orders['ID'])) {
                $orders = [$orders];
            }

            if (empty($orders)) {
                $cycleCompleted = true;
                break;
            }

            // Kalem işlemeyi atla (sadece sipariş alanları) — AMA bizde hiç olmayan ya da
            // kalemsiz kalmış siparişin kalemleri yazılır; yoksa new modunun kaçırdığı
            // sipariş panelde "ürün bilgisi yok" diye ertesi sabaha kadar kalıyordu.
            $ids = array_map(fn ($o) => (string) ($o['ID'] ?? ''), $orders);
            $withItems = Order::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->whereIn('platform_order_id', $ids)
                ->where('items_count', '>', 0)
                ->pluck('platform_order_id')
                ->flip();

            foreach ($orders as $orderData) {
                if (isset($withItems[(string) ($orderData['ID'] ?? '')])) {
                    unset($orderData['Urunler']);
                }
                $this->syncOrder($orderData, $channel);
                $processed++;
            }

            if ($page % 10 === 0 || $page === 1) {
                $el = round(microtime(true) - $startTime, 1);
                $this->info("[status] Sayfa {$page}: {$processed} siparis ({$el}sn)");
            }

            if (count($orders) < 200) {
                $cycleCompleted = true;
                break;
            }

            $page++;
            usleep(300000);
        }

        // Resume cursor KULLANILMIYOR (her koşu en yeni sayfadan başlar). Eski cursor
        // anahtarlarını temizle ki devralınan bayat pozisyon davranışı bozmasın.
        $settings = $channel->fresh()->settings ?? [];
        unset($settings['status_resume_page'], $settings['status_resume_since']);
        $settings['last_status_sync_at'] = now()->toIso8601String();
        if ($cycleCompleted) {
            $this->info("[status] En yeni degisiklikler tazelendi ({$processed} siparis).");
        }

        // last_live_order_id güncelle
        if ($processed > 0) {
            $newMaxId = (int) Order::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->selectRaw('MAX(platform_order_id::int) as max_id')
                ->value('max_id');

            $currentLastId = (int) ($settings['last_live_order_id'] ?? 0);

            if ($newMaxId > $currentLastId) {
                $settings['last_live_order_id'] = $newMaxId;
            }
        }

        $channel->update(['settings' => $settings]);

        if ($processed > 0) {
            $this->recalculateCustomerTotals($channel);
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("[status] Tamamlandi: {$processed} siparis ({$this->totalNew} yeni, {$this->totalUpdated} guncellenen), sayfa {$page} ({$elapsed}sn)");

        Log::info('SyncCrmOrdersLive[status]: completed', [
            'since' => $since,
            'processed' => $processed,
            'page' => $page,
            'cycle_completed' => $cycleCompleted,
            'new' => $this->totalNew,
            'updated' => $this->totalUpdated,
            'duration' => $elapsed,
        ]);

        return 0;
    }

    /**
     * Katman 3: Güvenlik taraması — son 30 gün tam sync.
     * Kalemler dahil (UrunGetir=true), ödeme dahil.
     * Günlük gece 04:00'te çalışır.
     */
    protected function syncSafety(TicimaxService $service, Channel $channel): int
    {
        $since = now()->subDays(30)->format('Y-m-d\TH:i:s');

        $filters = [
            'DuzenlemeTarihiBas' => $since,
            'OdemeGetir' => true,
        ];

        $page = 1;
        $processed = 0;
        $startTime = microtime(true);

        $this->info("[safety] Son 30 gun taranıyor (>= {$since})...");

        while ($page <= self::MAX_PAGES) {
            $result = $service->getOrders($filters, $page, 200, true);

            if (! $result['success']) {
                $this->error("[safety] Sayfa {$page}: API hatasi — " . ($result['error'] ?? 'Bilinmeyen'));

                sleep(5);
                $retryResult = $service->getOrders($filters, $page, 200, true);

                if (! $retryResult['success']) {
                    $this->error("[safety] Sayfa {$page}: Retry basarisiz, durduruluyor.");
                    Log::error('SyncCrmOrdersLive[safety]: API error', [
                        'page' => $page,
                        'error' => $retryResult['error'] ?? 'unknown',
                    ]);

                    break;
                }

                $result = $retryResult;
            }

            $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];

            if (! empty($orders) && isset($orders['ID'])) {
                $orders = [$orders];
            }

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $orderData) {
                $this->syncOrder($orderData, $channel);
                $processed++;
            }

            if ($page % 10 === 0 || $page === 1) {
                $el = round(microtime(true) - $startTime, 1);
                $this->info("[safety] Sayfa {$page}: {$processed} siparis ({$el}sn)");
            }

            if (count($orders) < 200) {
                break;
            }

            $page++;
            usleep(300000);
        }

        if ($processed > 0) {
            $newMaxId = (int) Order::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->selectRaw('MAX(platform_order_id::int) as max_id')
                ->value('max_id');

            $settings = $channel->fresh()->settings ?? [];
            $currentLastId = (int) ($settings['last_live_order_id'] ?? 0);

            if ($newMaxId > $currentLastId) {
                $settings['last_live_order_id'] = $newMaxId;
            }

            $settings['last_safety_sync_at'] = now()->toIso8601String();
            $channel->update(['settings' => $settings]);

            $this->recalculateCustomerTotals($channel);
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("[safety] Tamamlandi: {$processed} siparis ({$this->totalNew} yeni, {$this->totalUpdated} guncellenen), {$page} sayfa ({$elapsed}sn)");

        Log::info('SyncCrmOrdersLive[safety]: completed', [
            'processed' => $processed,
            'pages' => $page,
            'new' => $this->totalNew,
            'updated' => $this->totalUpdated,
            'duration' => $elapsed,
        ]);

        return 0;
    }

    private function invalidMode(string $mode): int
    {
        $this->error("Gecersiz mod: {$mode}. Kullanim: new, status, safety");

        return 1;
    }
}
