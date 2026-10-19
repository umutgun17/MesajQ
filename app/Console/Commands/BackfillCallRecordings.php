<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\Channel;
use App\Services\NetgsmService;
use Illuminate\Console\Command;

/**
 * Çağrı DEVAM EDERKEN erken yakalanıp süre=0 / kayıt boş kalmış answered
 * çağrıları NetGSM ham CDR'ından yeniden çekip kurtarır.
 *
 * Kök sebep SyncCalls::processRecords içinde düzeltildi; bu komut geçmiş
 * (zaten bozulmuş) kayıtları toplu olarak telafi eder. NetGSM ses kayıtlarını
 * belli bir süre sakladığı için yalnızca kaydı hâlâ duran çağrılar kurtarılır.
 */
class BackfillCallRecordings extends Command
{
    protected $signature = 'call:backfill-recordings
        {--limit=100000 : En fazla kaç çağrı denensin}
        {--channel= : NetGSM kanal ID (boşsa netgsm-call tipli ilk kanal)}
        {--days= : Sadece son N günde başlamış çağrılar}
        {--delay=300 : İstekler arası bekleme (ms)}
        {--dry-run : Sadece neyin kurtarılacağını göster, DB yazma}';

    protected $description = 'Erken yakalanıp süre=0/kayıt boş kalmış answered çağrıları NetGSM CDR\'ından yeniden çekip kurtarır';

    // Operatör (gerçek görüşme) dışı IVR hedefleri — CallController ile aynı
    protected array $ivrPatterns = ['mesaidisi', 'oglearasi', 'ivr', 'karsilama', 'announcement', 'Puanlama', 'interactive'];

    public function handle(): int
    {
        $channel = $this->option('channel')
            ? Channel::find($this->option('channel'))
            : Channel::where('type', 'netgsm-call')->first();

        if (! $channel) {
            $this->error('NetGSM kanalı bulunamadı.');
            return 1;
        }

        $service = NetgsmService::fromChannel($channel);
        if (! $service->isAvailable()) {
            $this->error("Kanal #{$channel->id}: NetGSM credential eksik.");
            return 1;
        }

        $query = Call::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('direction', 'inbound')
            ->where('status', 'answered')
            ->where('duration', 0)
            ->whereNull('recording_url');

        foreach ($this->ivrPatterns as $p) {
            $query->where('called', 'not ilike', "%{$p}%");
        }

        if ($days = $this->option('days')) {
            $query->where('started_at', '>=', now()->subDays((int) $days));
        }

        $calls = $query->orderByDesc('started_at')->limit((int) $this->option('limit'))->get();
        $total = $calls->count();

        if ($total === 0) {
            $this->info('Backfill edilecek çağrı yok.');
            return 0;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info("Denenecek: {$total} çağrı | dry-run=" . ($dryRun ? 'evet' : 'hayır'));

        $recovered = 0;   // güncellenen (süre ve/veya kayıt düzeldi)
        $withAudio = 0;   // kaydı da gelen (işlenebilir)
        $noData = 0;      // NetGSM'de bulunamadı / kayıt silinmiş
        $noBetter = 0;    // daha iyi veri yok
        $recoveredIds = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($calls as $call) {
            $match = $this->fetchBetterRecord($service, $call);

            if ($match === null) {
                $noData++;
            } elseif ($match === false) {
                $noBetter++;
            } else {
                $hasAudio = ! empty($match['recording_url']);

                if (! $dryRun) {
                    $updates = [
                        'duration' => $match['duration'],
                        'status' => $match['status'] ?? $call->status,
                        'extension' => $match['extension'] ?? $call->extension,
                        'ring_duration' => max((int) ($match['ring_duration'] ?? 0), (int) $call->ring_duration),
                    ];
                    if ($hasAudio) {
                        $updates['recording_url'] = $match['recording_url'];
                        $updates['recording_path'] = null;
                        $updates['transcribed_at'] = null;
                    }
                    $call->update($updates);
                }

                $recovered++;
                if ($hasAudio) {
                    $withAudio++;
                    $recoveredIds[] = $call->id;
                }
            }

            $bar->advance();
            usleep((int) $this->option('delay') * 1000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Güncellenen: {$recovered} (kaydı da gelen: {$withAudio}) | veri yok/silinmiş: {$noData} | daha iyi veri yok: {$noBetter}");

        if (! empty($recoveredIds)) {
            $path = storage_path('app/backfill_recovered_ids.txt');
            file_put_contents($path, implode(',', $recoveredIds));
            $this->newLine();
            $this->line('Kurtarılan (kayıtlı) ID listesi yazıldı: ' . $path);
            $this->line('Transkript/analiz için:');
            $this->line('  php artisan call:process --channel=' . $channel->id
                . ' --ids="' . implode(',', $recoveredIds) . '" --limit=' . count($recoveredIds));
        }

        return 0;
    }

    /**
     * NetGSM'den çağrının commonID grubunu yeniden çek; daha iyi (daha uzun süre
     * veya eksik kayıt URL'i dolmuş) veri varsa parse edilmiş call dizisini döndür.
     *
     * @return array|false|null  array=daha iyi veri, false=daha iyi veri yok, null=bulunamadı
     */
    protected function fetchBetterRecord(NetgsmService $service, Call $call)
    {
        // started_at UTC saklanır; NetGSM API TR saati bekler.
        $tr = $call->started_at->copy()->setTimezone('Europe/Istanbul');
        $start = $tr->copy()->subMinutes(3);
        $end = $tr->copy()->addMinutes(20);

        $raw = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = $service->getCdr($start->format('dmYHi'), $end->format('dmYHi'));
            if (is_array($raw)) {
                break;
            }
            usleep(500000);
        }

        if (! is_array($raw) || empty($raw)) {
            return null;
        }

        $parsed = $service->groupCdrRecords($raw);

        // 1) common_id ile birebir eşle (en güvenilir)
        $match = null;
        if (! empty($call->common_id)) {
            foreach ($parsed as $cl) {
                if (($cl['common_id'] ?? null) == $call->common_id) {
                    $match = $cl;
                    break;
                }
            }
        }

        // 2) common_id eşleşmezse caller (son 10 hane) + yakın zamanla eşle
        if (! $match) {
            $num = Call::normalizePhone($call->caller);
            foreach ($parsed as $cl) {
                if (Call::normalizePhone($cl['caller'] ?? '') === $num) {
                    $match = $cl;
                    break;
                }
            }
        }

        if (! $match) {
            return null;
        }

        $betterDuration = (int) ($match['duration'] ?? 0) > (int) $call->duration;
        $hasRecording = ! empty($match['recording_url']);

        // Süre düzelmiş ya da kayıt URL'i gelmiş → kurtarılabilir
        if ($betterDuration || $hasRecording) {
            return $match;
        }

        return false;
    }
}
