<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daha önce işlenmiş çağrıların MP3 ve transcript'lerini düzeltir.
 * - MP3 dosyasını call duration'a göre yeniden kırpar (IVR bölümünü atar)
 * - Transcript'teki IVR kalıntılarını temizler
 * - Whisper veya Claude tekrar çalıştırmaz
 */
class ReprocessCallRecordings extends Command
{
    protected $signature = 'call:reprocess
        {--limit=50 : İşlenecek çağrı sayısı}
        {--ratio=2 : MP3/call duration oranı eşiği (bu değerden büyükler işlenir)}
        {--fix-transcript : Sadece transcript temizle, MP3 kırpma}
        {--dry-run : Sadece ne yapılacağını göster}
        {--ids= : Virgülle ayrılmış ID listesi}';

    protected $description = 'Sorunlu MP3 dosyalarını yeniden kırp ve transcript temizle';

    protected string $ffmpegPath = '/home/yunbi/bin/ffmpeg';
    protected int $fixed = 0;
    protected int $skipped = 0;
    protected int $failed = 0;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $ratioThreshold = (float) $this->option('ratio');
        $fixTranscriptOnly = $this->option('fix-transcript');
        $dryRun = $this->option('dry-run');
        $ids = $this->option('ids') ? explode(',', $this->option('ids')) : null;

        $this->info("Reprocess başlatılıyor | ratio>{$ratioThreshold} | limit={$limit} | dry={$dryRun}");

        // Hedef çağrıları bul
        $query = Call::withoutGlobalScopes()
            ->whereNotNull('transcribed_at')
            ->whereNotNull('recording_path')
            ->where('recording_path', '!=', 'FAILED');

        if ($ids) {
            $query->whereIn('id', $ids);
        }

        $calls = $query->select('id', 'tenant_id', 'duration', 'recording_path', 'transcription', 'started_at')
            ->orderBy('id')
            ->get();

        $this->info("Toplam adaylar: {$calls->count()}");

        $processed = 0;
        foreach ($calls as $call) {
            if ($processed >= $limit) break;

            $hasIvrInTranscript = $this->hasIvrResidues($call->transcription ?? '');

            $path = Storage::disk('local')->path($call->recording_path);
            $needsMp3Fix = false;
            $mp3Duration = 0;
            $ratio = 0;

            if (! $fixTranscriptOnly && file_exists($path)) {
                $mp3Duration = $this->getMp3Duration($path);
                if ($mp3Duration > 0) {
                    $ratio = $mp3Duration / max($call->duration, 1);
                    $needsMp3Fix = $ratio > $ratioThreshold;
                }
            }

            $needsTranscriptFix = $hasIvrInTranscript;

            if (! $needsMp3Fix && ! $needsTranscriptFix) {
                continue;
            }

            $processed++;

            if ($dryRun) {
                $mp3Sec = round($mp3Duration);
                $reasons = [];
                if ($needsMp3Fix) $reasons[] = "mp3:{$mp3Sec}s vs call:{$call->duration}s (ratio:{$ratio}x)";
                if ($needsTranscriptFix) $reasons[] = "IVR in transcript";
                $this->line("  [DRY] ID:{$call->id} — " . implode(', ', $reasons));
                continue;
            }

            $updateData = [];

            // 1. MP3 kırpma
            if ($needsMp3Fix && ! $fixTranscriptOnly) {
                $newPath = $this->retrimMp3($path, $call);
                if ($newPath) {
                    $updateData['recording_path'] = $newPath;
                    $newDur = $this->getMp3Duration(Storage::disk('local')->path($newPath));
                    $this->info("  ID:{$call->id} MP3 kırpıldı: {$mp3Duration}s → {$newDur}s (call:{$call->duration}s)");
                } else {
                    $this->warn("  ID:{$call->id} MP3 kırpma başarısız");
                    $this->failed++;
                }
            }

            // 2. Transcript temizleme
            if ($needsTranscriptFix && $call->transcription) {
                $clean = $this->cleanTranscript($call->transcription);
                if ($clean !== $call->transcription && ! empty(trim($clean))) {
                    $updateData['transcription'] = $clean;
                    $removed = strlen($call->transcription) - strlen($clean);
                    $this->info("  ID:{$call->id} Transcript temizlendi: -{$removed} karakter");
                }
            }

            if (! empty($updateData)) {
                $call->update($updateData);
                $this->fixed++;
            } else {
                $this->skipped++;
            }
        }

        $this->newLine();
        $this->info("Tamamlandı | Düzeltilen: {$this->fixed} | Atlanan: {$this->skipped} | Başarısız: {$this->failed}");
        if ($dryRun) {
            $this->warn("DRY RUN — hiçbir şey değiştirilmedi. Toplam {$processed} çağrı düzeltilecek.");
        }

        return self::SUCCESS;
    }

    protected function getMp3Duration(string $path): float
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $this->ffmpegPath);
        $out = trim((string) shell_exec(sprintf(
            '%s -i %s -show_entries format=duration -v quiet -of csv=p=0 2>/dev/null',
            escapeshellarg($ffprobePath),
            escapeshellarg($path)
        )));
        return (float) $out;
    }

    /**
     * MP3'ü sondan call duration kadar kırpar.
     * Mantık: NetGSM kaydı = IVR bekleme + gerçek konuşma. Konuşma hep sonda.
     */
    protected function retrimMp3(string $currentMp3, Call $call): ?string
    {
        $mp3Duration = $this->getMp3Duration($currentMp3);

        // Sondan (call duration + 15sn buffer) al
        $keepDuration = min($mp3Duration, $call->duration + 15);
        $trimStart = max(0, $mp3Duration - $keepDuration);

        // Eğer zaten yeterince kısa ise atla
        if ($trimStart < 5) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'retrim_') . '.mp3';

        $cmd = sprintf(
            '%s -y -i %s -ss %s -ac 1 -ar 16000 -b:a 64k %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($currentMp3),
            escapeshellarg(number_format($trimStart, 3, '.', '')),
            escapeshellarg($tmpFile)
        );
        exec($cmd, $_, $rc);

        if ($rc !== 0 || ! file_exists($tmpFile) || filesize($tmpFile) < 100) {
            @unlink($tmpFile);
            return null;
        }

        // Yeni MP3'ü kaydet (aynı path'e üzerine yaz)
        $tenant = Tenant::find($call->tenant_id);
        $slug = $tenant?->slug ?? 'unknown';
        $date = $call->started_at;
        $monthDir = $date->format('m_Y');
        $dayDir = $date->format('d');
        $fileName = "call_{$call->id}.mp3";
        $relativePath = "voices/{$slug}/{$monthDir}/{$dayDir}/{$fileName}";

        $fullDir = Storage::disk('local')->path("voices/{$slug}/{$monthDir}/{$dayDir}");
        if (! is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $destPath = Storage::disk('local')->path($relativePath);
        copy($tmpFile, $destPath);
        @unlink($tmpFile);

        return $relativePath;
    }

    protected function hasIvrResidues(string $text): bool
    {
        $clean = $this->cleanTranscript($text);
        if ($clean !== $text) {
            return true;
        }
        return false;

        foreach ($patterns as $p) {
            if (mb_stripos($first300, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function cleanTranscript(string $text): string
    {
        // ProcessCallRecordings'daki removeIvrTextResidues ile aynı mantık
        $processor = app(ProcessCallRecordings::class);

        // removeIvrTextResidues protected olduğu için reflection kullanıyoruz
        $method = new \ReflectionMethod($processor, 'removeIvrTextResidues');
        $method->setAccessible(true);

        return $method->invoke($processor, $text);
    }
}
