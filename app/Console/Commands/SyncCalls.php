<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\Channel;
use App\Services\NetgsmService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCalls extends Command
{
    protected $signature = 'call:sync
        {--channel= : Belirli kanal ID}
        {--minutes=10 : Kaç dakika geriye bak}
        {--backfill-days= : Geçmişe dönük kaç gün çekilsin}
        {--start-date= : Backfill başlangıç tarihi (Y-m-d, TR saati)}
        {--end-date= : Backfill bitiş tarihi (Y-m-d, TR saati)}
        {--delay=300 : Backfill istekleri arası bekleme (ms)}
        {--reverse : Sondan başa doğru çek}';

    protected $description = 'NetGSM santralden çağrı kayıtlarını (CDR) senkronize et';

    protected int $created = 0;
    protected int $updated = 0;
    protected int $skipped = 0;
    protected array $phoneCache = [];

    public function handle(): int
    {
        $channelId = $this->option('channel');
        $minutes = (int) $this->option('minutes');
        $backfillDays = $this->option('backfill-days');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');

        $query = Channel::withoutGlobalScopes()
            ->where('type', 'netgsm-call')
            ->where('is_active', true);

        if ($channelId) {
            $query->where('id', $channelId);
        }

        $channels = $query->get();

        if ($channels->isEmpty()) {
            $this->warn('Aktif NetGSM kanalı bulunamadı.');
            return 0;
        }

        foreach ($channels as $channel) {
            if ($startDate && $endDate) {
                $trStart = Carbon::parse($startDate, 'Europe/Istanbul')->startOfDay();
                $trEnd = Carbon::parse($endDate, 'Europe/Istanbul')->endOfDay();
                $this->backfillDateRange($channel, $trStart, $trEnd);
            } elseif ($backfillDays) {
                $this->backfillChannel($channel, (int) $backfillDays);
            } else {
                $this->syncChannel($channel, $minutes);
            }
        }

        $this->info("Tamamlandı: {$this->created} yeni, {$this->updated} güncellenen, {$this->skipped} mevcut.");
        return 0;
    }

    /**
     * UTC zamanı Türkiye saatine çevir (NetGSM API Türkiye saati bekler)
     */
    protected function toTurkeyTime(Carbon $utcTime): Carbon
    {
        return $utcTime->copy()->setTimezone('Europe/Istanbul');
    }

    /**
     * Normal sync: son X dakikayı çek (scheduler için)
     * NetGSM API max 1000 CDR döndürür, yoğun saatlerde 10dk bile 1000'i aşabilir.
     * Bu yüzden süreyi 5dk'lık chunk'lara böleriz.
     */
    protected function syncChannel(Channel $channel, int $minutes): void
    {
        $service = NetgsmService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->warn("Kanal #{$channel->id}: Credential eksik, atlanıyor.");
            return;
        }

        $tenantId = $channel->tenant_id;
        $endDate = now();
        $startDate = now()->subMinutes($minutes);

        // Türkiye saatine çevir (NetGSM API TR saati bekler)
        $trStart = $this->toTurkeyTime($startDate);
        $trEnd = $this->toTurkeyTime($endDate);

        $this->info("Kanal #{$channel->id}: {$trStart->format('d.m.Y H:i')} - {$trEnd->format('H:i')} TR arası çekiliyor...");

        // 1dk'lık chunk'larla çek (1000 limit aşımını önle)
        $current = $trStart->copy();
        $totalRecords = 0;

        while ($current->lt($trEnd)) {
            $chunkEnd = $current->copy()->addMinutes(1);
            if ($chunkEnd->gt($trEnd)) {
                $chunkEnd = $trEnd->copy();
            }

            $records = $service->getCdr($current->format('dmYHi'), $chunkEnd->format('dmYHi'));

            if ($records !== null && ! empty($records)) {
                $flat = 0;
                foreach ($records as $entry) {
                    $flat += count($entry['values'] ?? []);
                }
                $totalRecords += $flat;

                if ($flat >= 1000) {
                    $this->warn("  ⚠ {$current->format('H:i')}-{$chunkEnd->format('H:i')}: 1000 limit — bazı kayıtlar eksik olabilir.");
                }

                $this->processRecords($records, $service, $channel->id, $tenantId);
            }

            $current = $chunkEnd;
        }

        if ($totalRecords > 0) {
            $this->info("  {$totalRecords} CDR kaydı işlendi.");
        } else {
            $this->info("Kanal #{$channel->id}: Yeni çağrı yok.");
        }

        $channel->update(['last_synced_at' => now()]);
    }

    /**
     * Backfill: belirli tarih aralığını 1dk'lık chunk'larla çek
     * --reverse: sondan başa doğru çeker (veri bittiğinde erken durdurmak için)
     */
    protected function backfillDateRange(Channel $channel, Carbon $trStart, Carbon $trEnd): void
    {
        $service = NetgsmService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->warn("Kanal #{$channel->id}: Credential eksik, atlanıyor.");
            return;
        }

        $tenantId = $channel->tenant_id;
        $delay = (int) $this->option('delay');
        $reverse = $this->option('reverse');

        $direction = $reverse ? '(reverse)' : '(forward)';
        $this->info("Backfill {$direction}: {$trStart->format('d.m.Y')} - {$trEnd->format('d.m.Y')}");

        $chunkCount = 0;
        $emptyStreak = 0;

        if ($reverse) {
            // Sondan başa: 1dk'lık chunk'larla geriye git
            $current = $trEnd->copy();

            while ($current->gt($trStart)) {
                $chunkStart = $current->copy()->subMinutes(1);
                if ($chunkStart->lt($trStart)) {
                    $chunkStart = $trStart->copy();
                }

                $records = $service->getCdr($chunkStart->format('dmYHi'), $current->format('dmYHi'));

                if ($records !== null && ! empty($records)) {
                    $beforeCreated = $this->created;
                    $this->processRecords($records, $service, $channel->id, $tenantId);
                    $newInChunk = $this->created - $beforeCreated;
                    $emptyStreak = 0;

                    if ($newInChunk > 0 || $chunkCount % 60 === 0) {
                        $this->info("  {$chunkStart->format('d.m H:i')} - {$current->format('H:i')}: +{$newInChunk} yeni (toplam: {$this->created})");
                    }
                } else {
                    $emptyStreak++;

                    if ($chunkCount % 120 === 0) {
                        $this->info("  {$chunkStart->format('d.m H:i')} tarıyor... (toplam: {$this->created}, boş streak: {$emptyStreak})");
                    }

                    // 4320 ardışık boş chunk = 72 saat (3 gün) veri yok → muhtemelen veri bitmiş
                    if ($emptyStreak >= 4320 && $this->created > 0) {
                        $this->warn("  ⚠ 72 saattir veri yok, durduruluyor.");
                        break;
                    }
                }

                $current = $chunkStart;
                $chunkCount++;

                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        } else {
            // İleri yön (mevcut davranış)
            $current = $trStart->copy();

            while ($current->lt($trEnd)) {
                $chunkEnd = $current->copy()->addMinutes(1);
                if ($chunkEnd->gt($trEnd)) {
                    $chunkEnd = $trEnd->copy();
                }

                $records = $service->getCdr($current->format('dmYHi'), $chunkEnd->format('dmYHi'));

                if ($records !== null && ! empty($records)) {
                    $beforeCreated = $this->created;
                    $this->processRecords($records, $service, $channel->id, $tenantId);
                    $newInChunk = $this->created - $beforeCreated;

                    if ($newInChunk > 0 || $chunkCount % 60 === 0) {
                        $this->info("  {$current->format('d.m H:i')} - {$chunkEnd->format('H:i')}: +{$newInChunk} yeni (toplam: {$this->created})");
                    }
                } elseif ($chunkCount % 120 === 0) {
                    $this->info("  {$current->format('d.m H:i')} tarıyor... (toplam: {$this->created})");
                }

                $current = $chunkEnd;
                $chunkCount++;

                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }

        $channel->update(['last_synced_at' => now()]);
    }

    /**
     * Backfill: geçmişe dönük 5dk'lık chunk'larla çek
     * NetGSM API max 1000 CDR döndürür, yoğun saatlerde 5dk bile 1000'i aşabilir.
     */
    protected function backfillChannel(Channel $channel, int $days): void
    {
        $service = NetgsmService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->warn("Kanal #{$channel->id}: Credential eksik, atlanıyor.");
            return;
        }

        $tenantId = $channel->tenant_id;
        $delay = (int) $this->option('delay');

        // Türkiye saatine çevir
        $endDate = $this->toTurkeyTime(now());
        $startDate = $this->toTurkeyTime(now()->subDays($days)->startOfDay());

        $this->info("Backfill: {$startDate->format('d.m.Y')} - {$endDate->format('d.m.Y')} ({$days} gün)");

        $current = $startDate->copy();
        $chunkCount = 0;

        while ($current->lt($endDate)) {
            // 1dk'lık chunk'lar (1000 limit aşımını azalt)
            $chunkEnd = $current->copy()->addMinutes(1);
            if ($chunkEnd->gt($endDate)) {
                $chunkEnd = $endDate->copy();
            }

            $records = $service->getCdr($current->format('dmYHi'), $chunkEnd->format('dmYHi'));

            if ($records !== null && ! empty($records)) {
                $beforeCreated = $this->created;
                $this->processRecords($records, $service, $channel->id, $tenantId);
                $newInChunk = $this->created - $beforeCreated;

                if ($newInChunk > 0 || $chunkCount % 60 === 0) { // 60 = 1h / 1dk
                    $this->info("  {$current->format('d.m H:i')} - {$chunkEnd->format('H:i')}: +{$newInChunk} yeni (toplam: {$this->created})");
                }
            }

            $current = $chunkEnd;
            $chunkCount++;

            // Rate limiting
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $channel->update(['last_synced_at' => now()]);
    }

    /**
     * CDR kayıtlarını işle ve DB'ye yaz
     *
     * Kuyruk sistemi aynı müşteri çağrısı için birden fazla commonID üretebilir.
     * Netgsm bunları tek çağrı olarak sayar. Biz de aynı numaradan 2dk içinde
     * gelen answered kayıtları birleştiriyoruz: en uzun süreliyi tutuyoruz.
     */
    protected function processRecords(array $rawResponse, NetgsmService $service, int $channelId, int $tenantId): void
    {
        $calls = $service->groupCdrRecords($rawResponse);

        foreach ($calls as $callData) {
            $existing = Call::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('common_id', $callData['common_id'])
                ->first();

            if ($existing) {
                $updates = [];

                // missed/voicemail → answered yükseltmesi
                if ($existing->status !== 'answered' && $callData['status'] === 'answered') {
                    $updates['status'] = $callData['status'];
                    $updates['extension'] = $callData['extension'] ?? $existing->extension;
                }

                // Çağrı DEVAM EDERKEN erken yakalanmış olabilir: ilk sync sadece
                // "operatör çalıyor (0 sn)" kaydını görür, görüşme bitince oluşan
                // asıl konuşma süresi sonraki sync'te gelir. Daha uzun süreyi al.
                if ((int) $callData['duration'] > (int) $existing->duration) {
                    $updates['duration'] = $callData['duration'];
                    $updates['extension'] = $callData['extension'] ?? ($updates['extension'] ?? $existing->extension);
                    $updates['ring_duration'] = max((int) $callData['ring_duration'], (int) $existing->ring_duration);
                }

                // Ses kaydı ilk sync'te henüz oluşmamıştı, görüşme bitince geldi.
                if (empty($existing->recording_url) && ! empty($callData['recording_url'])) {
                    $updates['recording_url'] = $callData['recording_url'];
                    // Kayıt yeni geldi → (boş) işleme verisini sıfırla ki yeniden işlensin
                    $updates['recording_path'] = null;
                    $updates['transcribed_at'] = null;
                }

                if (! empty($updates)) {
                    $existing->update($updates);
                    $this->updated++;
                } else {
                    $this->skipped++;
                }
                continue;
            }

            // Dedup: aynı numaradan 10dk içinde herhangi statüde çağrı var mı?
            // Kuyruk sistemi aynı çağrı için birden fazla commonID üretir.
            // Farklı commonID'ler farklı chunk'larda farklı statülerle gelebilir,
            // bu yüzden tüm statülere bakıp en iyi veriyi birleştiriyoruz.
            if ($callData['started_at'] && strlen($callData['caller']) > 4) {
                $recentDuplicate = Call::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('caller', $callData['caller'])
                    ->whereBetween('started_at', [
                        $callData['started_at']->copy()->subMinutes(10),
                        $callData['started_at']->copy()->addMinutes(10),
                    ])
                    ->first();

                if ($recentDuplicate) {
                    // Daha iyi veriyle güncelle: answered > voicemail > missed, en uzun süre
                    $statusPriority = ['missed' => 0, 'voicemail' => 1, 'answered' => 2];
                    $newPriority = $statusPriority[$callData['status']] ?? 0;
                    $existingPriority = $statusPriority[$recentDuplicate->status] ?? 0;

                    if ($newPriority > $existingPriority || $callData['duration'] > $recentDuplicate->duration) {
                        $dupUpdate = [
                            'common_id' => $callData['common_id'],
                            'status' => $newPriority >= $existingPriority ? $callData['status'] : $recentDuplicate->status,
                            'duration' => max($callData['duration'], $recentDuplicate->duration),
                            'extension' => $callData['extension'] ?? $recentDuplicate->extension,
                            'ring_duration' => max($callData['ring_duration'], $recentDuplicate->ring_duration),
                            'recording_url' => $callData['recording_url'] ?? $recentDuplicate->recording_url,
                        ];
                        // Kayıt ilk kez bu sync'te geldiyse → yeniden işlenmeli
                        if (empty($recentDuplicate->recording_url) && ! empty($callData['recording_url'])) {
                            $dupUpdate['recording_path'] = null;
                            $dupUpdate['transcribed_at'] = null;
                        }
                        $recentDuplicate->update($dupUpdate);
                        $this->updated++;
                    } else {
                        $this->skipped++;
                    }
                    continue;
                }
            }

            Call::create([
                'tenant_id' => $tenantId,
                'channel_id' => $channelId,
                'customer_id' => $this->findCustomerByPhone($callData['caller'], $tenantId),
                'common_id' => $callData['common_id'],
                'caller' => $callData['caller'],
                'called' => $callData['called'],
                'extension' => $callData['extension'],
                'direction' => $callData['direction'],
                'status' => $callData['status'],
                'started_at' => $callData['started_at'],
                'duration' => $callData['duration'],
                'ring_duration' => $callData['ring_duration'],
                'recording_url' => $callData['recording_url'],
            ]);

            $this->created++;
        }
    }

    /**
     * Telefon numarasına göre müşteri bul (sipariş adreslerinden).
     * Sonuçları cache'ler — aynı numara tekrar sorgulanmaz.
     */
    protected function findCustomerByPhone(string $phone, int $tenantId): ?int
    {
        $normalized = Call::normalizePhone($phone);

        if (strlen($normalized) < 10) {
            return null;
        }

        if (array_key_exists($normalized, $this->phoneCache)) {
            return $this->phoneCache[$normalized];
        }

        $customerId = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('customer_id')
            ->whereRaw("RIGHT(REGEXP_REPLACE(shipping_address->>'phone', '\\D', '', 'g'), 10) = ?", [$normalized])
            ->orderByDesc('id')
            ->value('customer_id');

        $this->phoneCache[$normalized] = $customerId;

        return $customerId;
    }
}
