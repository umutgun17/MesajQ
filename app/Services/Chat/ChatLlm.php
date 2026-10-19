<?php

namespace App\Services\Chat;

use App\Models\Tenant;
use App\Services\Ai\AiBudgetGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chatbot LLM istemcisi — çok sağlayıcılı (Anthropic → OpenAI → Groq).
 *
 * Neden çoklu: platform Anthropic kredisi bitince chatbot ÖLMEMELİ; sıradaki
 * çalışan sağlayıcıya düşer (2026-07-07: Anthropic kredisi boş, OpenAI canlı).
 * Başarısız sağlayıcı kısa süre cache'te "ölü" işaretlenir ki her mesajda
 * boşa 4xx yenmesin; süre dolunca kendiliğinden yeniden denenir.
 *
 * Sözleşme: complete() modelden STRICT JSON ister (system prompt'ta şema
 * ChatbotService tarafından tanımlanır) ve decode edilmiş diziyi döndürür.
 * JSON parse edilemezse ham metin 'reply' olarak sarılır (bot yine cevap verir).
 */
class ChatLlm
{
    /** AiBudgetGuard PRICING kind eşlemesi */
    protected const BUDGET_KIND = [
        'anthropic' => 'claude', // model haiku ise aşağıda 'claude-haiku'ya çevrilir
        'openai' => 'openai-mini',
        'groq' => 'groq',
    ];

    /**
     * @param  array<int, array{role: string, content: string}>  $messages  user|assistant sıralı geçmiş
     * @return array{json: array, provider: string}|null  null = hiçbir sağlayıcı cevap veremedi / bütçe aşıldı
     */
    /**
     * @param  string|array{0: string, 1: string}  $system  [statik, dinamik] verilirse
     *                statik parça Anthropic prompt cache'ine alınır (~%90 ucuz okuma).
     */
    public function complete(string|array $system, array $messages, ?int $tenantId = null): ?array
    {
        foreach ((array) config('chat.providers') as $provider) {
            if (Cache::get("chat:llm:dead:{$provider}")) {
                continue;
            }

            [$apiKey, $model, $usingSharedKey] = $this->credentials($provider, $tenantId);
            if ($apiKey === '') {
                continue; // anahtar yok — "ölü" işaretlemeye gerek yok, sıradakine geç
            }

            // Fren yalnız PAYLAŞIMLI platform anahtarında geçerli: kiracı kendi
            // anahtarını kullanıyorsa kendi faturasını öder, kısıtlanmaz.
            if (! AiBudgetGuard::allows($tenantId, $usingSharedKey)) {
                Log::warning('ChatLlm: AI budget exceeded, skipping provider', ['tenant_id' => $tenantId, 'provider' => $provider]);

                continue;
            }

            $result = match ($provider) {
                'anthropic' => $this->callAnthropic($system, $messages, $apiKey, $model),
                // OpenAI/Groq tarafında cache yok; iki parça birleştirilir
                'openai' => $this->callOpenAiCompatible($system, $messages, 'https://api.openai.com/v1/chat/completions', $apiKey, $model),
                'groq' => $this->callOpenAiCompatible($system, $messages, 'https://api.groq.com/openai/v1/chat/completions', $apiKey, $model),
                default => null,
            };

            if ($result === null) {
                // Sağlayıcı çalışmıyor (kredi/anahtar/ağ) → kısa süre atla, sıradakine geç
                Cache::put("chat:llm:dead:{$provider}", 1, (int) config('chat.dead_provider_ttl', 300));

                continue;
            }

            [$text, $inTokens, $outTokens] = $result;
            $kind = self::BUDGET_KIND[$provider] ?? 'claude';
            if ($kind === 'claude' && str_contains($model, 'haiku')) {
                $kind = 'claude-haiku';
            }
            AiBudgetGuard::record($tenantId, $inTokens, $outTokens, $kind);

            return ['json' => $this->parseJson($text), 'provider' => $provider];
        }

        Log::error('ChatLlm: all providers failed', ['tenant_id' => $tenantId]);

        return null;
    }

    /**
     * Sağlayıcının anahtarı, modeli ve anahtarın PAYLAŞIMLI olup olmadığı.
     *
     * Anthropic'te kiracı panelden kendi anahtarını girmiş olabilir
     * (`settings.claude_api_key` — ClaudeService'in yıllardır kullandığı alan).
     * Chatbot bunu okumadığı için 4 Eyl'e kadar platformun ölü .env anahtarına
     * takılıp gpt-4o-mini'ye düşüyordu; kiracının çalışan anahtarı boşta duruyordu.
     *
     * @return array{0: string, 1: string, 2: bool} [anahtar, model, paylaşımlı mı]
     */
    protected function credentials(string $provider, ?int $tenantId): array
    {
        if ($provider === 'anthropic') {
            $shared = (string) config('services.claude.api_key');
            $own = '';
            if ($tenantId) {
                $settings = Tenant::find($tenantId)?->settings ?? [];
                $own = trim((string) ($settings['claude_api_key'] ?? ''));
            }
            $key = $own !== '' ? $own : $shared;

            return [$key, (string) config('chat.anthropic_model'), $key === $shared];
        }

        if ($provider === 'openai') {
            return [(string) config('services.openai.api_key'), (string) config('chat.openai_model'), true];
        }

        if ($provider === 'groq') {
            return [(string) env('GROQ_API_KEY'), (string) config('chat.groq_model'), true];
        }

        return ['', '', true];
    }

    /** @return array{0: string, 1: int, 2: int}|null [text, input_tokens, output_tokens] */
    protected function callAnthropic(string|array $system, array $messages, string $apiKey, string $model): ?array
    {
        // Statik kurallar bloğu her mesajda aynı → cache'e alınır (5 dk TTL, okuma
        // ~%90 ucuz; yoğun saatte tüm ziyaretçiler aynı prefix'i paylaşır). Dinamik
        // onaylı-bilgi bloğu breakpoint'ten SONRA gelir ki prefix bozulmasın.
        $blocks = is_array($system)
            ? array_values(array_filter([
                ['type' => 'text', 'text' => (string) $system[0], 'cache_control' => ['type' => 'ephemeral']],
                trim((string) ($system[1] ?? '')) !== '' ? ['type' => 'text', 'text' => (string) $system[1]] : null,
            ]))
            : [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout((int) config('chat.timeout', 25))->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => (int) config('chat.max_tokens', 700),
                'system' => $blocks,
                'messages' => $messages,
            ]);

            if ($response->failed()) {
                Log::warning('ChatLlm: anthropic failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);

                return null;
            }

            // Fable/yeni modellerde content[0] thinking olabilir — text tipli bloğu bul
            $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? '';
            if ($text === '') {
                return null;
            }

            $usage = $response->json('usage', []);

            // Cache okuması ~0,1x, yazması ~1,25x fiyatlı — bütçe sayacına
            // yaklaşık maliyetiyle yansısın (aksi halde fren yanlış yerde kalkar).
            $input = (int) ($usage['input_tokens'] ?? 0)
                + (int) round(((int) ($usage['cache_read_input_tokens'] ?? 0)) * 0.1)
                + (int) round(((int) ($usage['cache_creation_input_tokens'] ?? 0)) * 1.25);

            return [$text, $input, (int) ($usage['output_tokens'] ?? 0)];
        } catch (\Throwable $e) {
            Log::warning('ChatLlm: anthropic exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /** OpenAI ve Groq aynı chat/completions şemasını konuşur. */
    protected function callOpenAiCompatible(string|array $system, array $messages, string $url, string $apiKey, string $model): ?array
    {
        if ($apiKey === '') {
            return null;
        }

        $system = is_array($system) ? trim(implode("\n\n", array_filter($system))) : $system;

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('chat.timeout', 25))
                ->post($url, [
                    'model' => $model,
                    'max_tokens' => (int) config('chat.max_tokens', 700),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => array_merge(
                        [['role' => 'system', 'content' => $system]],
                        $messages
                    ),
                ]);

            if ($response->failed()) {
                Log::warning('ChatLlm: openai-compatible failed', ['url' => $url, 'status' => $response->status(), 'error' => $response->json('error.message')]);

                return null;
            }

            $text = (string) $response->json('choices.0.message.content', '');
            if ($text === '') {
                return null;
            }

            $usage = $response->json('usage', []);

            return [$text, (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0)];
        } catch (\Throwable $e) {
            Log::warning('ChatLlm: openai-compatible exception', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Model çıktısından JSON çıkar. Kod bloğu çitlerini soy, decode dene;
     * olmazsa ham metni reply olarak sar (bot sessiz kalmasın).
     */
    protected function parseJson(string $text): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $clean);

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Metin içine gömülü ilk {...} bloğunu dene
        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['reply' => trim($text), 'action' => 'none', 'confidence' => 0.5];
    }
}
