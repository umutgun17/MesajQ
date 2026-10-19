<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\TicimaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCrmOrdersById extends SyncCrmOrders
{
    protected $signature = 'crm:sync-orders-byid
                            {--start=1 : Baslangic siparis ID}
                            {--end=545000 : Bitis siparis ID}
                            {--channel= : Belirli kanal ID}
                            {--fresh : Mevcut siparisleri sil ve sifirdan basla}
                            {--retry : Eksik ID listesinden tekrar dene (storage/app/missing_order_ids.json)}
                            {--retry-attempts=3 : Retry denemesi sayisi}';

    protected $description = 'Siparisleri ID bazinda tek tek senkronize eder (eksiksiz sync)';

    public function handle(): int
    {
        $query = Channel::withoutGlobalScopes()
            ->where('is_active', true)
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

        if ($this->option('retry')) {
            return $this->handleRetry($service, $channel);
        }

        $startId = (int) $this->option('start');
        $endId = (int) $this->option('end');

        // Fresh start: delete all orders and items for this channel
        if ($this->option('fresh')) {
            $this->info('Mevcut siparisler siliniyor...');

            // Delete items first (FK)
            $deletedItems = OrderItem::whereIn(
                'order_id',
                Order::withoutGlobalScopes()->where('channel_id', $channel->id)->select('id')
            )->delete();

            $deletedOrders = Order::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->delete();

            $this->info("  Silindi: {$deletedOrders} siparis, {$deletedItems} kalem");
        }

        $found = 0;
        $notFound = 0;
        $errors = 0;
        $consecutiveErrors = 0;
        $startTime = microtime(true);

        $this->info("Sync basliyor: ID {$startId} -> {$endId}");

        for ($id = $startId; $id <= $endId; $id++) {
            try {
                $result = $service->getOrders(['SiparisID' => $id, 'OdemeGetir' => true], 1, 1);

                if (! $result['success']) {
                    $errors++;
                    $consecutiveErrors++;

                    // Log occasional errors
                    if ($errors % 100 === 1) {
                        Log::warning("SyncCrmOrdersById: ID {$id} API error", [
                            'error' => $result['error'] ?? 'unknown',
                        ]);
                    }

                    if ($consecutiveErrors >= 20) {
                        $this->warn("  ID {$id}: {$consecutiveErrors} ardisik hata, 10sn bekleniyor...");
                        sleep(10);
                        $consecutiveErrors = 0;
                    }

                    continue;
                }

                $consecutiveErrors = 0;

                $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];

                // Single result comes as associative array
                if (! empty($orders) && isset($orders['ID'])) {
                    $orders = [$orders];
                }

                if (empty($orders)) {
                    $notFound++;
                } else {
                    $this->syncOrder($orders[0], $channel);
                    $found++;
                }

            } catch (\Exception $e) {
                $errors++;
                $consecutiveErrors++;

                if ($errors % 100 === 1) {
                    Log::warning("SyncCrmOrdersById: ID {$id} exception", [
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($consecutiveErrors >= 20) {
                    $this->warn("  ID {$id}: Exception surge, 10sn bekleniyor...");
                    sleep(10);
                    $consecutiveErrors = 0;
                }

                continue;
            }

            // Progress every 1000 IDs
            if ($id % 1000 === 0) {
                $elapsed = microtime(true) - $startTime;
                $processed = $id - $startId + 1;
                $perSec = $elapsed > 0 ? $processed / $elapsed : 0;
                $remaining = $perSec > 0 ? ($endId - $id) / $perSec : 0;
                $eta = $this->formatDuration((int) $remaining);
                $mem = round(memory_get_usage(true) / 1024 / 1024);

                $this->info("  ID {$id}/{$endId}: {$found} bulundu, {$notFound} bos, {$errors} hata | Hiz: " . round($perSec, 1) . "/sn | ETA: {$eta} | RAM: {$mem}MB");

                // Force garbage collection every 5000 IDs
                if ($id % 5000 === 0) {
                    $service->resetClients();
                    gc_collect_cycles();
                }
            }

            // Recalculate customer totals every 25000 found orders
            if ($found > 0 && $found % 25000 === 0) {
                $this->recalculateCustomerTotals($channel);
            }

            // Save progress every 10000 IDs
            if ($id % 10000 === 0) {
                $settings = $channel->settings ?? [];
                $settings['last_byid_sync_id'] = $id;
                $channel->update(['settings' => $settings]);
            }
        }

        // Final recalculate
        if ($found > 0) {
            $this->recalculateCustomerTotals($channel);
        }

        // Save final state
        $settings = $channel->settings ?? [];
        $settings['last_byid_sync_id'] = $endId;
        $channel->update(['settings' => $settings]);

        $elapsed = microtime(true) - $startTime;

        $this->info("Tamamlandi: {$found} siparis bulundu, {$notFound} bos ID, {$errors} hata | Sure: " . $this->formatDuration((int) $elapsed));
        $this->info("Yeni: {$this->totalNew}, Guncellenen: {$this->totalUpdated}, Kalem: {$this->totalItems}");

        Log::info('SyncCrmOrdersById: completed', [
            'start_id' => $startId,
            'end_id' => $endId,
            'found' => $found,
            'not_found' => $notFound,
            'errors' => $errors,
            'new' => $this->totalNew,
            'updated' => $this->totalUpdated,
            'items' => $this->totalItems,
            'duration_seconds' => (int) $elapsed,
        ]);

        return 0;
    }

    protected function handleRetry(TicimaxService $service, $channel): int
    {
        $file = storage_path('app/missing_order_ids.json');

        if (! file_exists($file)) {
            $this->error('Eksik ID dosyasi bulunamadi: ' . $file);

            return 1;
        }

        $ids = json_decode(file_get_contents($file), true);

        if (empty($ids)) {
            $this->info('Eksik ID yok, dosya bos.');

            return 0;
        }

        $maxAttempts = (int) $this->option('retry-attempts');
        $found = 0;
        $notFound = 0;
        $failed = [];
        $total = count($ids);

        $this->info("Retry basliyor: {$total} eksik ID, max {$maxAttempts} deneme");

        foreach ($ids as $index => $id) {
            $success = false;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $result = $service->getOrders(['SiparisID' => $id, 'OdemeGetir' => true], 1, 1);

                    if (! $result['success']) {
                        if ($attempt < $maxAttempts) {
                            sleep(2);

                            continue;
                        }
                        $failed[] = ['id' => $id, 'error' => $result['error'] ?? 'unknown'];

                        break;
                    }

                    $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];

                    if (! empty($orders) && isset($orders['ID'])) {
                        $orders = [$orders];
                    }

                    if (empty($orders)) {
                        $notFound++;
                    } else {
                        $this->syncOrder($orders[0], $channel);
                        $found++;
                    }

                    $success = true;

                    break;
                } catch (\Exception $e) {
                    if ($attempt < $maxAttempts) {
                        sleep(2);

                        continue;
                    }
                    $failed[] = ['id' => $id, 'error' => $e->getMessage()];
                }
            }

            // Progress every 50 IDs
            if (($index + 1) % 50 === 0) {
                $progress = $index + 1;
                $failCount = count($failed);
                $this->info("  {$progress}/{$total}: {$found} bulundu, {$notFound} bos, {$failCount} hata");
            }
        }

        // Recalculate customer totals
        if ($found > 0) {
            $this->recalculateCustomerTotals($channel);
        }

        $this->info("Retry tamamlandi: {$found} bulundu, {$notFound} bos, " . count($failed) . ' basarisiz');

        if (! empty($failed)) {
            $failedIds = array_column($failed, 'id');
            // Kanal-bazlı dosya adı: tek paylaşımlı dosya çok-tenant'ta birbirini eziyordu.
            file_put_contents(storage_path("app/failed_order_ids-ch{$channel->id}.json"), json_encode($failed, JSON_PRETTY_PRINT));
            $this->warn('Basarisiz ID\'ler: ' . implode(', ', $failedIds));
            $this->info("Detay: storage/app/failed_order_ids-ch{$channel->id}.json");

            Log::warning('SyncCrmOrdersById retry: failed IDs', ['ids' => $failedIds]);
        }

        return empty($failed) ? 0 : 1;
    }

    protected function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}g {$hours}s {$minutes}dk";
        }

        if ($hours > 0) {
            return "{$hours}s {$minutes}dk";
        }

        return "{$minutes}dk";
    }
}
