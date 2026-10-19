<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCallRecordings extends Command
{
    protected $signature = 'call:process
        {--limit=10 : Kaç çağrı işlensin}
        {--delay=500 : İstekler arası bekleme (ms)}
        {--skip-download : Sadece transkript ve analiz yap (kayıt zaten indirilmişler için)}
        {--skip-analysis : Sadece indir ve transkript yap, AI analiz yapma}
        {--min-duration=10 : Minimum çağrı süresi (sn)}
        {--channel= : Belirli kanal ID}
        {--ids= : Sadece bu çağrı ID listesini işle (virgülle ayrılmış)}
        {--dry-run : Sadece ne yapılacağını göster}';

    protected $description = 'Ses kayıtlarını indir, IVR kırp, MP3 dönüştür, Whisper transkript ve Claude analiz';

    protected int $processed = 0;
    protected int $failed = 0;
    protected int $skipped = 0;

    protected string $ffmpegPath = '/home/yunbi/bin/ffmpeg';

    // IVR pattern'leri — başlangıç
    protected array $startIvrPatterns = [
        'altyazı m.k',
        'altyaz m',
        'altyazı m',
        'müzik',
        'şu anda bütün müşteri temsilcilerimiz',
        'şu anda bütün müşterilerimiz',
        'tüm temsilcilerimiz meşguldür',
        'tüm biletlerimiz size',
        'tümcümüz aramanızı',
        'arayıcı sıra numaranız',
        'aray s numaran',
        'sıra numaranız',
        'beklediğiniz için teşekkürler',
        'bekledi i te',
        'lütfen hatta kalınız',
        'lütfen hatta kalın',
        'lütfen haklı kalınız',
        'hatta kal',
        'en kısa sürede müşteri hizmetlerimiz',
        'en kısa sürede müşteri',
        'en k s m hizmetlerimiz',
        'en k s m hizmet',
        'en k s m',
        'müsait durumdaki ilk temsilcimiz',
        'müsaitlerimdeki ilk temsilcimiz',
        'diğer müşterilerimize hizmet vermektedir',
        'müşterilerimize hizmet vermektedir',
        'müşteri temsilcilerimiz',
        'müşteri temsilci',
        'temsilcilerimiz me',
        'temsilcilerimiz di',
        'anda b m temsilcilerimiz',
        'size yardımcı olacaktır',
        'size yard olacakt',
        'izlediğiniz için teşekkür',
        'bu videoyu izlediğiniz',
        'bu dizinin betimlemesi',
        'sesli betimleme',
        'iyi seyirler',
        'ilk sıradasınız ve ilk müsait',
        'ilk müsait temsilci sizi yanıtlayacaktır',
        'aramanızı yanıtlayacaktır',
        'araman yan',
        'operatöre bağlanmak için lütfen bekleyiniz',
        'operatöre bağlanmak için lütfen',
        'dahili numarayı biliyorsanız',
        'sorularınız için 1',
        'satış sonrası destek için 2',
        'muhasebe için lütfen 3',
        'durumdaki ilk temsilcimiz',
    ];

    // IVR pattern'leri — bitiş
    protected array $endIvrPatterns = [
        'düşünceleriniz bizim için',
        'müşteri hizmetlerimizle yaptığınız görüşmeyi',
        '1 en düşük, 5 en yüksek',
        'tuşlamanızı önemle rica ediyoruz',
        'puanlayınız',
        'değerlendirmeniz bizim için',
        'anketimize katılınız',
        'memnuniyet anket',
        'önemle rica ediyoruz',
    ];

    // Konuşma başlangıç pattern'leri — bunlardan biri bulunursa kesin konuşma başlamıştır
    protected array $conversationStartPatterns = [
        'merhabalar tony black',
        'tony black nasıl yardımcı',
        'nasıl yardımcı olabilirim',
        'nasıl yardımcı olabilir',
        'tony black müşteri',
        'hoş geldiniz',
        'buyurun efendim',
        'merhaba nasıl yardımcı',
    ];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $minDuration = (int) $this->option('min-duration');
        $channelId = $this->option('channel');
        $skipDownload = $this->option('skip-download');

        $query = Call::withoutGlobalScopes()
            ->whereNotNull('recording_url')
            ->where('recording_url', '!=', '')
            ->where('status', 'answered')
            ->where('duration', '>=', $minDuration)
            ->whereNull('transcribed_at')
            ->orderBy('started_at', 'desc');

        if ($channelId) {
            $query->where('channel_id', $channelId);
        }

        if ($idsOption = $this->option('ids')) {
            $ids = array_filter(array_map('trim', explode(',', $idsOption)));
            $query->whereIn('id', $ids);
        }

        // skip-download: sadece recording_path olanları işle
        if ($skipDownload) {
            $query->whereNotNull('recording_path')
                ->where('recording_path', '!=', '')
                ->where('recording_path', '!=', 'FAILED');
        }

        $total = (clone $query)->count();
        $calls = $query->limit($limit)->get();

        if ($calls->isEmpty()) {
            $this->info('İşlenecek çağrı yok.');
            return 0;
        }

        $this->info("Toplam bekleyen: {$total} | Bu turda: {$calls->count()} çağrı işlenecek...");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Tarih', 'Süre', 'Arayan', 'Dahili'],
                $calls->map(fn ($c) => [$c->id, $c->started_at, $c->formattedDuration(), $c->caller, $c->extension])
            );
            return 0;
        }

        $bar = $this->output->createProgressBar($calls->count());
        $bar->start();

        foreach ($calls as $call) {
            $this->processCall($call);
            $bar->advance();
            usleep((int) $this->option('delay') * 1000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Tamamlandı: {$this->processed} başarılı, {$this->failed} hata, {$this->skipped} atlandı.");

        return 0;
    }

    protected function processCall(Call $call): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'call_');
        $tmpWav = $tmpBase . '.wav';
        $tmpMp3 = $tmpBase . '.mp3';

        try {
            // 1. WAV indir
            $wavPath = $this->downloadRecording($call, $tmpWav);
            if (! $wavPath) {
                $this->failed++;
                return;
            }

            // 2. Kaba kırpma + MP3: sondan (duration × 1.5 + 30sn) al
            //    IVR/bekleme hep başta → sondan almak %90+ IVR'ı keser
            //    Bu MP3 hem diske kaydedilir hem Whisper'a gönderilir
            $realDuration = (float) trim(shell_exec(sprintf(
                '%s -i %s -show_entries format=duration -v quiet -of csv=p=0 2>/dev/null',
                escapeshellarg(str_replace('ffmpeg', 'ffprobe', $this->ffmpegPath)),
                escapeshellarg($wavPath)
            )));

            $targetDuration = min($realDuration, $call->duration * 1.5 + 30);
            $trimStart = max(0, $realDuration - $targetDuration);

            $cmd = sprintf(
                '%s -y -i %s -ss %s -ac 1 -ar 16000 -b:a 64k %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($wavPath),
                escapeshellarg(number_format($trimStart, 3, '.', '')),
                escapeshellarg($tmpMp3)
            );
            exec($cmd, $_, $rc);

            if ($rc !== 0 || ! file_exists($tmpMp3) || filesize($tmpMp3) < 100) {
                $this->logWarn($call, 'ffmpeg dönüşüm hatası');
                $this->failed++;
                return;
            }

            // WAV artık gereksiz
            @unlink($tmpWav);
            @unlink($tmpBase);

            // 3. Bu kırpılmış MP3'ü Whisper'a gönder
            $whisperResult = $this->whisperWithTimestamps($tmpMp3, $call->tenant_id);
            if (! $whisperResult) {
                $this->logWarn($call, 'Whisper başarısız');
                $this->failed++;
                return;
            }

            // 4. İnce IVR kırpma: Whisper segment'lerinden kalan IVR parçalarını tespit et
            $boundaries = $this->detectConversationBoundaries($whisperResult['segments'], $whisperResult['duration']);

            // 5. Temiz transkript oluştur (IVR segment'leri hariç)
            $cleanTranscription = $this->buildCleanTranscription($whisperResult['segments'], $boundaries);

            if (empty(trim($cleanTranscription))) {
                $this->logWarn($call, 'Temiz transkript boş — atlanıyor');
                $this->skipped++;
                return;
            }

            // 6. İnce kırpma gerekiyorsa MP3'ü yeniden kırp, gerekmiyorsa olduğu gibi kaydet
            $mp3Saved = null;
            if ($boundaries['start'] > 5 || ($whisperResult['duration'] - $boundaries['end']) > 5) {
                // Hâlâ IVR kalmış — ince kırpma yap
                $tmpFineMp3 = $tmpBase . '_fine.mp3';
                $fineCmd = sprintf(
                    '%s -y -i %s -ss %s -to %s -c copy %s 2>&1',
                    escapeshellarg($this->ffmpegPath),
                    escapeshellarg($tmpMp3),
                    escapeshellarg(number_format($boundaries['start'], 3, '.', '')),
                    escapeshellarg(number_format($boundaries['end'], 3, '.', '')),
                    escapeshellarg($tmpFineMp3)
                );
                exec($fineCmd, $_, $fineRc);
                if ($fineRc === 0 && file_exists($tmpFineMp3) && filesize($tmpFineMp3) > 100) {
                    $mp3Saved = $this->saveRecording($tmpFineMp3, $call);
                    @unlink($tmpFineMp3);
                } else {
                    $mp3Saved = $this->saveRecording($tmpMp3, $call);
                }
            } else {
                // Kaba kırpma yeterli olmuş
                $mp3Saved = $this->saveRecording($tmpMp3, $call);
            }

            // 7. AI Analiz
            $analysis = [];
            if (! $this->option('skip-analysis')) {
                $analysis = $this->analyzeWithClaude($cleanTranscription, $call);
            }

            // 8. DB güncelle
            $updateData = [
                'transcription' => $cleanTranscription,
                'transcribed_at' => now(),
            ];

            if ($mp3Saved) {
                $updateData['recording_path'] = $mp3Saved;
            }

            if (! empty($analysis)) {
                $updateData['ai_summary'] = $analysis['summary'] ?? null;
                $updateData['ai_sentiment'] = $analysis['sentiment'] ?? null;
                $updateData['ai_category'] = $analysis['category'] ?? null;
                $updateData['ai_metadata'] = $analysis;
            }

            $call->update($updateData);

            $this->processed++;
            $whisperSec = round($whisperResult['duration']);
            $savedSec = round($realDuration - $whisperResult['duration']);
            $this->logInfo($call, "OK | Whisper:{$whisperSec}s (kayıt:{$realDuration}s, -{$savedSec}s) | " . strlen($cleanTranscription) . ' char');
        } catch (\Exception $e) {
            $this->logError($call, $e->getMessage());
            Log::error('ProcessCallRecordings error', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->failed++;
        } finally {
            @unlink($tmpBase);
            @unlink($tmpWav);
            @unlink($tmpMp3);
        }
    }

    // ── Download ──

    protected function downloadRecording(Call $call, string $tmpWav): ?string
    {
        if ($this->option('skip-download') && $call->recording_path) {
            $fullPath = Storage::disk('local')->path($call->recording_path);
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        $response = Http::timeout(120)
            ->withOptions(['sink' => $tmpWav])
            ->get($call->recording_url);

        if (! $response->successful() || ! file_exists($tmpWav) || filesize($tmpWav) < 1000) {
            $this->logWarn($call, 'Ses kaydı indirilemedi (HTTP ' . ($response->status() ?? '?') . ')');
            return null;
        }

        return $tmpWav;
    }

    // ── Whisper with timestamps ──

    protected function whisperWithTimestamps(string $filePath, int $tenantId): ?array
    {
        $tenant = Tenant::find($tenantId);
        $settings = $tenant?->settings ?? [];

        $groqKey = $settings['groq_api_key'] ?? config('services.groq.api_key', '');
        if (! empty($groqKey)) {
            return $this->whisperVerbose($filePath, $groqKey,
                'https://api.groq.com/openai/v1/audio/transcriptions', 'whisper-large-v3');
        }

        $openaiKey = $settings['openai_api_key'] ?? config('services.openai.api_key', '');
        if (! empty($openaiKey)) {
            return $this->whisperVerbose($filePath, $openaiKey,
                'https://api.openai.com/v1/audio/transcriptions', 'whisper-1');
        }

        $this->warn('Whisper API key bulunamadı.');
        return null;
    }

    protected function whisperVerbose(string $filePath, string $apiKey, string $url, string $model): ?array
    {
        $maxRetries = 3;
        $fileName = str_ends_with($filePath, '.mp3') ? 'call.mp3' : 'call.wav';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $client = new \GuzzleHttp\Client();
                $response = $client->post($url, [
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                    'multipart' => [
                        ['name' => 'file', 'contents' => fopen($filePath, 'r'), 'filename' => $fileName],
                        ['name' => 'model', 'contents' => $model],
                        ['name' => 'language', 'contents' => 'tr'],
                        ['name' => 'response_format', 'contents' => 'verbose_json'],
                        ['name' => 'timestamp_granularities[]', 'contents' => 'segment'],
                    ],
                    'timeout' => 180,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                return [
                    'duration' => $data['duration'] ?? 0,
                    'text' => $data['text'] ?? '',
                    'segments' => $data['segments'] ?? [],
                ];
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
                $body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';

                // Rate limit → bekle ve tekrar dene
                if ($status === 429 && $attempt < $maxRetries) {
                    // Retry-After header veya body'den bekleme süresi al
                    $waitSeconds = 60;
                    if (preg_match('/try again in (\d+(?:\.\d+)?)s/', $body, $m)) {
                        $waitSeconds = (int) ceil((float) $m[1]) + 5;
                    } elseif (preg_match('/try again in (\d+)m/', $body, $m)) {
                        $waitSeconds = (int) $m[1] * 60 + 10;
                    }
                    $this->newLine();
                    $this->warn("  Rate limit — {$waitSeconds}sn bekleniyor (deneme {$attempt}/{$maxRetries})...");
                    sleep($waitSeconds);
                    continue;
                }

                // 413 Request too large → kurtarılamaz
                if ($status === 413) {
                    Log::warning('Whisper: dosya çok büyük', ['file' => $filePath, 'size' => filesize($filePath)]);
                    return null;
                }

                Log::warning('Whisper API client error', [
                    'url' => $url,
                    'status' => $status,
                    'body' => substr($body, 0, 500),
                ]);
                return null;
            } catch (\Exception $e) {
                Log::error('Whisper API exception', ['url' => $url, 'error' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }

    // ── IVR Detection ──

    protected function detectConversationBoundaries(array $segments, float $totalDuration): array
    {
        $conversationStart = 0.0;
        $conversationEnd = $totalDuration;

        // Strateji 1: "Merhabalar Tony Black" gibi kesin konuşma başlangıcı ara
        $foundConversationStart = false;
        foreach ($segments as $segment) {
            $text = mb_strtolower(trim($segment['text'] ?? ''));
            if ($this->isConversationStart($text)) {
                $conversationStart = (float) ($segment['start'] ?? 0);
                $foundConversationStart = true;
                break;
            }
        }

        // Strateji 2: Kesin başlangıç bulunamazsa, IVR pattern'leriyle ilerle
        if (! $foundConversationStart) {
            foreach ($segments as $segment) {
                $text = mb_strtolower(trim($segment['text'] ?? ''));
                if ($this->isStartIvr($text) || $this->isWhisperHallucination($text)) {
                    $conversationStart = max($conversationStart, (float) ($segment['end'] ?? 0));
                } else {
                    // IVR olmayan segment bulundu — ama Whisper hallucination olabilir
                    // Segment kısa ve anlamsızsa (< 3 kelime) atla
                    $wordCount = str_word_count($text);
                    if ($wordCount < 3) {
                        $conversationStart = max($conversationStart, (float) ($segment['end'] ?? 0));
                        continue;
                    }
                    break;
                }
            }
        }

        // Sondan tara — son IVR segment'lerini bul
        $reversedSegments = array_reverse($segments);
        foreach ($reversedSegments as $segment) {
            $text = mb_strtolower(trim($segment['text'] ?? ''));
            if ($this->isEndIvr($text) || $this->isWhisperHallucination($text)) {
                $conversationEnd = min($conversationEnd, (float) ($segment['start'] ?? $totalDuration));
            } else {
                $wordCount = str_word_count($text);
                if ($wordCount < 3) {
                    $conversationEnd = min($conversationEnd, (float) ($segment['start'] ?? $totalDuration));
                    continue;
                }
                break;
            }
        }

        // Güvenlik: en az 5 saniye konuşma olmalı
        if ($conversationEnd - $conversationStart < 5) {
            $conversationStart = 0;
            $conversationEnd = $totalDuration;
        }

        return [
            'start' => $conversationStart,
            'end' => $conversationEnd,
            'original_duration' => $totalDuration,
            'trimmed_duration' => round($conversationEnd - $conversationStart, 1),
        ];
    }

    protected function isConversationStart(string $text): bool
    {
        foreach ($this->conversationStartPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    protected function isWhisperHallucination(string $text): bool
    {
        // Whisper bekleme müziğini tekrarlayan anlamsız metne çevirir
        $hallucinations = [
            'uçak uçuyor', 'gel gel', 'bir daha bir daha',
            'yarım yanacak', 'bırak kalmıyor', 'aldı mı uçak',
            'çok tatlı gibi', 'izlediğiniz için teşekkürler',
            'izlediğiniz için teşekkür ederim',
            'abone ol', 'like at', 'subscribe',
            'iyi seyirler', 'sesli betimleme',
            'bu dizinin betimlemesi trt',
            'teşekkürler buyurun',
            'classical', 'alors que bien',
            'ne i bu lan',
        ];
        foreach ($hallucinations as $h) {
            if (str_contains($text, $h)) {
                return true;
            }
        }

        // Aynı kelimenin 3+ kez tekrarı (müzik halüsinasyonu)
        $words = explode(' ', $text);
        if (count($words) >= 3) {
            $counts = array_count_values($words);
            foreach ($counts as $word => $count) {
                if ($count >= 3 && mb_strlen($word) > 2) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isStartIvr(string $text): bool
    {
        foreach ($this->startIvrPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        // Kısa/boş segment'ler veya bilinen IVR varyasyonları
        $exactMatches = ['', 'müzik', 'altyazı m.k.', 'altyaz m', 'altyazı m', 'bağırma!'];
        if (in_array($text, $exactMatches)) {
            return true;
        }

        // MÜZİK (büyük harf varyasyonu)
        if (preg_match('/^m[üu]zik$/iu', $text)) {
            return true;
        }

        return false;
    }

    protected function isEndIvr(string $text): bool
    {
        foreach ($this->endIvrPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // ── Save Recording ──

    protected function saveRecording(string $mp3Path, Call $call): ?string
    {
        // Klasör yapısı: voices/{tenant_slug}/{MM_YYYY}/{DD}/call_{id}.mp3
        $tenant = Tenant::find($call->tenant_id);
        $slug = $tenant?->slug ?? 'unknown';
        $date = $call->started_at;
        $monthDir = $date->format('m_Y');    // 01_2026
        $dayDir = $date->format('d');         // 15
        $fileName = "call_{$call->id}.mp3";

        $relativePath = "voices/{$slug}/{$monthDir}/{$dayDir}/{$fileName}";
        $fullDir = Storage::disk('local')->path("voices/{$slug}/{$monthDir}/{$dayDir}");

        if (! is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $destPath = Storage::disk('local')->path($relativePath);
        copy($mp3Path, $destPath);

        return $relativePath;
    }

    // ── Clean Transcription ──

    protected function buildCleanTranscription(array $segments, array $boundaries): string
    {
        $start = $boundaries['start'];
        $end = $boundaries['end'];
        $parts = [];

        foreach ($segments as $segment) {
            $segStart = (float) ($segment['start'] ?? 0);
            $segEnd = (float) ($segment['end'] ?? 0);
            $text = mb_strtolower(trim($segment['text'] ?? ''));

            // Sadece konuşma bölümündeki segment'leri al
            if ($segStart >= $start && $segEnd <= $end) {
                // Ortadaki IVR segment'lerini de filtrele
                if ($this->isStartIvr($text) || $this->isEndIvr($text)) {
                    continue;
                }
                $originalText = trim($segment['text'] ?? '');
                if (! empty($originalText)) {
                    $parts[] = $originalText;
                }
            }
        }

        $raw = implode(' ', $parts);

        // Metin bazlı IVR kalıntılarını temizle
        return $this->removeIvrTextResidues($raw);
    }

    protected function removeIvrTextResidues(string $text): string
    {
        $ivrPhrases = [
            'Altyazı M.K.',
            'Altyaz M',
            'Altyazı M',
            'Müzik',
            'MÜZİK',
            'Şu anda bütün müşteri temsilcilerimiz diğer müşterilerimize hizmet vermektedir.',
            'Şu anda bütün müşterilerimiz meşguldür.',
            'En kısa sürede müşteri hizmetlerimiz size yardımcı olacaktır.',
            'En kısa sürede müşteri hizmetli.',
            'Tüm temsilcilerimiz meşguldür.',
            'Tüm biletlerimiz size yardımcı olacaktır.',
            'Lütfen hatta kalınız.',
            'Lütfen hatta kalın.',
            'Lütfen haklı kalınız.',
            'Müsait durumdaki ilk temsilcimiz aramanızı yanıtlayacaktır.',
            'Müsaitlerimdeki ilk temsilcimiz aramanızı yanıtlayacaktır.',
            'Müsait durumdaki üç sensizimiz aramanızı yanıtlayacaktır.',
            'İlk sıradasınız ve ilk müsait temsilci sizi yanıtlayacaktır.',
            'Beklediğiniz için teşekkürler.',
            'Düşünceleriniz bizim için çok önemli.',
            'Müşteri hizmetlerimizle yaptığınız görüşmeyi',
            'Hizmetlerimizle yaptığınız görüşmeyi',
            'Lütfen 1 en düşük, 5 en yüksek olacak şekilde tuşlamanızı önemle rica ediyoruz.',
            'En kısa sürede müşteri arayacağız.',
            'İzlediğiniz için teşekkür ederim.',
            'Bu videoyu izlediğiniz için teşekkürler.',
            'Operatöre bağlanmak için lütfen bekleyiniz.',
            'Dahili numarayı biliyorsanız lütfen tuşlayınız.',
            'İyi seyirler.',
        ];

        foreach ($ivrPhrases as $phrase) {
            $text = str_replace($phrase, '', $text);
        }

        // Regex ile kalan IVR pattern'leri (Whisper bozuk çıktıları dahil)
        $ivrRegexes = [
            '/Aray[ıi]c?[ıi]? s[ıi]ra numaran[ıi]z? \d+\.?/u',
            '/Aray s numaran \d+/u',
            '/S numaran \d+/u',
            '/Bekledi[ğg]i[nm]iz? i[çc]in te[şs]ekk[üu]rler\.?/u',
            '/Bekledi i te/u',
            '/Hizmetlerimiz size yard olacakt[ıi]?/u',
            '/En k s m hizmetlerimiz size yard olacakt/u',
            '/En k s m hizmet[^\s]*/u',
            '/En k s m/u',
            '/anda b m temsilcilerimiz di m hizmet verm[eo]ktedir/u',
            '/anda b m temsilcilerimiz di m hizmet ver[a-zığüşöç]*/ui',
            '/anda b m temsilcilerimiz di m/u',
            '/anda b m temsilcilerimiz/u',
            '/T temsilcilerimiz me L hatta kal M durumdaki ilk temsilcimiz araman yan/u',
            '/T temsilcilerimiz me L hatta kal/u',
            '/T temsilcilerimiz me/u',
            '/M anda b m temsilcilerimiz/u',
            '/M M anda b m/u',
            '/durumdaki ilk temsilcimiz araman yan/u',
            '/Müsait durumdaki ilk temsilcimiz araman yan/u',
            '/İlk s[ıi]radas[ıi]n[ıi]z ve ilk müsait temsilci sizi yan[ıi]tlayacakt[ıi]r\.?/u',
            '/temsilcimiz araman[ıi]z[ıi]? yan[ıi]tlayacakt[ıi]r\.?/u',
            '/temsilcimiz araman yan/u',
            '/Bağırma!/u',
            '/Uçak uçuyor\.?/u',
            '/Gel Gel/u',
            '/Bir daha bir daha/u',
            '/Yarım yanacak[,.]? bırak kalmıyor\.?/u',
            '/Çok tatlı gibi\.?/u',
            '/Aldı mı uçak uçuyor\.?/u',
            '/Aldı\.? Uçak uçuyor\.?/u',
            // Anket / puanlama sonu
            '/lütfen bir en düşük[,]? beş en yüksek olacak şekilde tuşlamanızı öneml[ie] rica ediyoruz\.?/ui',
            '/bir en düşük[,]? 5 en yüksek olacak şekilde tuşlamanızı öneml[ie] rica ediyoruz\.?/ui',
            '/en düşük[,]? 5 en yüksek olacak şekilde tuşlamanızı öneml[ie] rica ediyoruz\.?/ui',
            '/5 en yüksek olacak şekilde tuşlamanızı öneml[ie] rica ediyoruz\.?/ui',
            '/en yüksek olacak şekilde tuşlamanızı öneml[ie] rica ediyoruz\.?/ui',
            '/tuşlamanızı öneml[ie] rica ediyoruz\.?/ui',
            '/en düşük[,]? beş en yüksek olacak şekilde kışlamanızı öneml[ie] rica ediyoruz\.?/ui',
            // Menü yönlendirmeleri
            '/Bilmiyorsanız sorularınız için 1\'?i[,.]? satış sonrası destek için 2\'?y?i?/ui',
            '/[Ss]orularınız için 1\'?i?[,.]? satış sonrası destek için 2/ui',
            '/[Mm]uhasebe için lütfen 3\'?e? tuşlayınız\.?/ui',
            '/[Kk]asebi için lütfen 3\'?e? tuşlayınız\.?/ui',
            // Whisper hallucination
            '/İzlediğiniz için teşekkür ederim\.?/ui',
            '/İyi seyirler\.?/ui',
            '/[Ss]esli [Bb]etimleme/u',
            '/[Bb]u dizinin betimlemesi TRT taraf/u',
            // Altyazı M.K. ile başlayan IVR blokları
            '/Altyazı M\.K\.?/u',
            '/Altyaz M\.?/u',
            // Kalan Whisper bozuk IVR çıktıları
            '/En k s\b/u',
            '/En g m\b/u',
            '/Tüm tüccülerimiz diğer müşterilerimize hizmet vermektedir\.?/u',
            '/Şu anda bütün temsilcilerimiz meşguldür\.?/u',
            '/Tüm temsilcileriniz meşguldür\.?/u',
            '/Tüm temsilcilerimiz meşgul\.?/u',
            // Bozuk IVR segment cümleleri
            '/Görüşmeyi l\b/u',
            '/hatta kal[ıi]n[ıi]z\.?/ui',
            '/T temsilcilerimiz me L hatta kal M durumdaki ilk temsilcimiz araman yan\.?/u',
            '/T temsilcilerimiz di m hizmet vermektedir/u',
            '/Müşteri hizmetlerimiz size yardımcı olacaktır\.?/u',
            '/En kısa sürede müşteri hizmetlerimiz\.?/u',
            '/En kısa sürede müşteri[^\.]*/u',
            // Catch-all: anda b m temsilci* (Whisper garbles)
            '/anda b m temsilci\S*/u',
            '/anda b m\b/u',
            // temsilcilerimiz di/me variants followed by garbled text
            '/temsilcilerimiz di m hizmet[^\.]*(?:\.|$)/u',
            '/temsilcilerimiz di[ğg]er[^\.]*(?:\.|$)/u',
            '/temsilcilerimiz me[şs]guldür\.?/u',
            '/temsilcilerimiz me[şs]gul\.?/u',
            // araman yan (garbled "aramanızı yanıtlayacaktır")
            '/araman yan\b/u',
            // Altyazı M.K. (with optional trailing dot variations)
            '/Altyazı M\.K\.?\s*/u',
        ];

        foreach ($ivrRegexes as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        // Çoklu boşlukları temizle
        return trim(preg_replace('/\s{2,}/', ' ', $text));
    }

    // ── Claude Analysis ──

    protected function analyzeWithClaude(string $transcription, Call $call): array
    {
        $tenant = Tenant::find($call->tenant_id);
        $settings = $tenant?->settings ?? [];
        $apiKey = \App\Services\Ai\ClaudeKeyPolicy::allows($call->tenant_id, 'calls')
            ? ($settings['claude_api_key'] ?? config('services.claude.api_key', ''))
            : ''; // bakiye kapısı: çağrı analizi panelden açılmadıkça Claude harcamaz
        $model = 'claude-haiku-4-5-20251001'; // Maliyet optimize — çağrı analizi için yeterli

        if (empty($apiKey)) {
            return [];
        }

        $prompt = <<<PROMPT
Sen bir e-ticaret çağrı merkezi analiz uzmanısın. Aşağıdaki telefon görüşmesini analiz et.
Bu analiz, ileride müşteri çağrılarını otomatik yanıtlayacak bir AI asistan eğitmek için kullanılacak.

Çağrı Bilgileri:
- Arayan: {$call->caller}
- Dahili: {$call->extension}
- Süre: {$call->formattedDuration()}
- Yön: {$call->direction}

Transkript:
{$transcription}

Aşağıdaki JSON formatında yanıt ver:
{
  "summary": "Görüşmenin 1-2 cümlelik Türkçe özeti",
  "sentiment": "positive | neutral | negative",
  "category": "satis_oncesi | siparis_takip | iade_degisim | sikayet | bilgi_talebi | teknik_destek | diger (TEK bir değer seç, array/liste YAZMA)",
  "keywords": ["anahtar", "kelimeler"],

  "customer_intent": "Müşterinin asıl amacı/talebi (1 cümle)",
  "resolution_status": "resolved | escalated | pending | unresolved",
  "resolution_note": "Nasıl çözüldü veya neden çözülemedi (1 cümle)",

  "product_references": ["Bahsedilen ürün adları veya türleri"],
  "order_references": ["Bahsedilen sipariş numaraları veya detayları"],

  "customer_profile": {
    "mood_start": "angry | frustrated | neutral | polite | friendly",
    "mood_end": "angry | frustrated | neutral | polite | friendly | satisfied",
    "is_repeat_caller": true/false,
    "loyalty_indicator": "new | regular | vip | unknown"
  },

  "agent_performance": {
    "empathy_score": 1-5,
    "solution_quality": 1-5,
    "communication_clarity": 1-5,
    "notes": "Temsilcinin iyi yaptığı veya geliştirebileceği şeyler (1 cümle)"
  },

  "dialog_flow": [
    {"role": "customer", "intent": "Müşterinin bu adımda ne istediği"},
    {"role": "agent", "action": "Temsilcinin verdiği yanıt/aksiyon"}
  ],

  "ai_training": {
    "ideal_response_strategy": "AI bu çağrıyı alsaydı izlemesi gereken strateji (2-3 cümle)",
    "key_info_needed": ["AI'ın bu tür çağrıyı çözmek için erişmesi gereken bilgiler"],
    "sample_phrases": ["Temsilcinin kullandığı etkili ifadeler"]
  },

  "customer_satisfied": true/false,
  "action_required": true/false,
  "action_note": "Yapılması gereken aksiyon varsa kısa not"
}

Sadece JSON döndür, başka bir şey yazma.
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 2000,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->successful()) {
                $text = $response->json('content.0.text', '');
                if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
                    $text = trim($m[1]);
                }
                $parsed = json_decode($text, true);
                if ($parsed) {
                    return $parsed;
                }
            }

            Log::warning('Claude call analysis error', [
                'call_id' => $call->id,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error('Claude call analysis exception', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    // ── Logging helpers ──

    protected function logInfo(Call $call, string $msg): void
    {
        $this->newLine();
        $this->info("  [{$call->id}] {$msg}");
    }

    protected function logWarn(Call $call, string $msg): void
    {
        $this->newLine();
        $this->warn("  [{$call->id}] {$msg}");
    }

    protected function logError(Call $call, string $msg): void
    {
        $this->newLine();
        $this->error("  [{$call->id}] {$msg}");
    }
}
