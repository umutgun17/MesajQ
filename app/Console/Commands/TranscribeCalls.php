<?php

namespace App\Console\Commands;

use App\Models\Call;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscribeCalls extends Command
{
    protected $signature = 'call:transcribe
        {--limit=10 : Kaç çağrı işlensin}
        {--channel= : Belirli kanal ID}';

    protected $description = 'Ses kaydı olan çağrıları Whisper ile yazıya çevir ve Claude ile analiz et';

    protected int $transcribed = 0;
    protected int $failed = 0;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $channelId = $this->option('channel');

        $query = Call::withoutGlobalScopes()
            ->whereNotNull('recording_url')
            ->whereNull('transcribed_at')
            ->where('duration', '>', 0)
            ->orderBy('started_at', 'desc');

        if ($channelId) {
            $query->where('channel_id', $channelId);
        }

        $calls = $query->limit($limit)->get();

        if ($calls->isEmpty()) {
            $this->info('İşlenecek çağrı yok.');
            return 0;
        }

        $this->info("{$calls->count()} çağrı işlenecek...");

        foreach ($calls as $call) {
            $this->processCall($call);
        }

        $this->info("Tamamlandı: {$this->transcribed} başarılı, {$this->failed} hata.");
        return 0;
    }

    protected function processCall(Call $call): void
    {
        try {
            // 1. Ses kaydını geçici dosyaya indir
            $tmpBase = tempnam(sys_get_temp_dir(), 'call_');
            $tmpPath = $tmpBase . '.wav';

            try {
                $response = Http::timeout(120)
                    ->withOptions(['sink' => $tmpPath])
                    ->get($call->recording_url);

                if (! $response->successful() || ! file_exists($tmpPath) || filesize($tmpPath) < 1000) {
                    $this->warn("  [{$call->id}] Ses kaydı indirilemedi.");
                    $this->failed++;
                    return;
                }

                // 2. Whisper ile transcribe et
                $transcription = $this->whisperTranscribe($tmpPath, $call->tenant_id);
                if (! $transcription) {
                    $this->warn("  [{$call->id}] Transcription başarısız.");
                    $this->failed++;
                    return;
                }
            } finally {
                @unlink($tmpBase); // tempnam'ın oluşturduğu boş dosya
                @unlink($tmpPath); // .wav uzantılı asıl dosya
            }

            // 3. Claude ile analiz et
            $analysis = $this->analyzeWithClaude($transcription, $call);

            // 4. Kaydet
            $call->update([
                'transcription' => $transcription,
                'ai_summary' => $analysis['summary'] ?? null,
                'ai_sentiment' => $analysis['sentiment'] ?? null,
                'ai_category' => $analysis['category'] ?? null,
                'ai_metadata' => $analysis,
                'transcribed_at' => now(),
            ]);

            $this->transcribed++;
            $this->info("  [{$call->id}] Transkript ve analiz tamamlandı.");
        } catch (\Exception $e) {
            $this->error("  [{$call->id}] Hata: {$e->getMessage()}");
            Log::error('TranscribeCalls error', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
            $this->failed++;
        }
    }

    protected function whisperTranscribe(string $filePath, int $tenantId): ?string
    {
        $tenant = \App\Models\Tenant::find($tenantId);
        $settings = $tenant?->settings ?? [];

        // Groq Whisper (öncelikli, daha hızlı ve ucuz)
        $groqKey = $settings['groq_api_key'] ?? config('services.groq.api_key', '');
        if (! empty($groqKey)) {
            return $this->transcribeWithGroq($filePath, $groqKey);
        }

        // OpenAI Whisper (fallback)
        $openaiKey = $settings['openai_api_key'] ?? config('services.openai.api_key', '');
        if (! empty($openaiKey)) {
            return $this->transcribeWithOpenAI($filePath, $openaiKey);
        }

        $this->warn('Whisper API key bulunamadı (Groq veya OpenAI).');
        return null;
    }

    protected function transcribeWithGroq(string $filePath, string $apiKey): ?string
    {
        try {
            $response = Http::timeout(120)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model' => 'whisper-large-v3',
                    'language' => 'tr',
                    'response_format' => 'text',
                ]);

            if ($response->successful()) {
                return trim($response->body());
            }

            Log::warning('Groq Whisper API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Groq Whisper API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function transcribeWithOpenAI(string $filePath, string $apiKey): ?string
    {
        try {
            $response = Http::timeout(120)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'tr',
                    'response_format' => 'text',
                ]);

            if ($response->successful()) {
                return trim($response->body());
            }

            Log::warning('OpenAI Whisper API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('OpenAI Whisper API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function analyzeWithClaude(string $transcription, Call $call): array
    {
        $tenant = \App\Models\Tenant::find($call->tenant_id);
        $settings = $tenant?->settings ?? [];
        $apiKey = \App\Services\Ai\ClaudeKeyPolicy::allows($call->tenant_id, 'calls')
            ? ($settings['claude_api_key'] ?? config('services.claude.api_key', ''))
            : ''; // bakiye kapısı: çağrı analizi panelden açılmadıkça Claude harcamaz
        $model = $settings['ai_model'] ?? 'claude-sonnet-4-5-20250929';

        if (empty($apiKey)) {
            return ['summary' => null, 'sentiment' => null, 'category' => null];
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
                // Claude bazen JSON'u ```json ... ``` ile sarar
                if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
                    $text = trim($m[1]);
                }
                $parsed = json_decode($text, true);
                if ($parsed) {
                    return $parsed;
                }
            }
        } catch (\Exception $e) {
            Log::error('Claude call analysis exception', ['error' => $e->getMessage()]);
        }

        return ['summary' => null, 'sentiment' => null, 'category' => null];
    }
}
