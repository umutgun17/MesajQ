<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\Ai\AiBudgetGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    protected string $apiKey;
    protected string $model;
    protected int $dimensions;
    protected ?int $tenantId;
    protected bool $usingSharedKey;

    public function __construct(?int $tenantId = null)
    {
        // Try tenant settings first, then .env fallback
        $this->tenantId = $tenantId;
        $this->apiKey = $this->resolveApiKey($tenantId);
        $this->usingSharedKey = ($this->apiKey === config('services.openai.api_key', ''));
        $this->model = config('services.openai.embedding_model', 'text-embedding-3-small');
        $this->dimensions = config('services.openai.embedding_dimensions', 1536);
    }

    /**
     * Create an instance for a specific tenant.
     */
    public static function forTenant(?int $tenantId): static
    {
        return new static($tenantId);
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate embedding for a single text.
     *
     * @return float[]|null
     */
    public function embed(string $text): ?array
    {
        $text = $this->prepareInput($text);
        if (empty($text)) {
            return null;
        }

        $result = $this->embedBatch([$text]);

        return $result[0] ?? null;
    }

    /**
     * Generate embeddings for multiple texts (max 2048 per batch).
     *
     * @param  string[]  $texts
     * @return array<int, float[]|null>
     */
    public function embedBatch(array $texts): array
    {
        if (! $this->isAvailable()) {
            return array_fill(0, count($texts), null);
        }

        $prepared = array_map(fn ($t) => $this->prepareInput($t), $texts);

        // Filter out empty strings but track original indices
        $validIndices = [];
        $validTexts = [];
        foreach ($prepared as $i => $t) {
            if (! empty($t)) {
                $validIndices[] = $i;
                $validTexts[] = $t;
            }
        }

        if (empty($validTexts)) {
            return array_fill(0, count($texts), null);
        }

        // Harcama freni: bütçe aşıldıysa embedding üretme (keyword arama devreye girer).
        if (! AiBudgetGuard::allows($this->tenantId, $this->usingSharedKey)) {
            Log::warning('EmbeddingService: AI budget exceeded, skipping embeddings', [
                'tenant_id' => $this->tenantId,
                'shared_key' => $this->usingSharedKey,
            ]);

            return array_fill(0, count($texts), null);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(60)->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $validTexts,
                'dimensions' => $this->dimensions,
            ]);

            if ($response->failed()) {
                Log::error('EmbeddingService: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return array_fill(0, count($texts), null);
            }

            // Token kullanımını harcama sayacına işle
            $usage = $response->json('usage', []);
            AiBudgetGuard::record($this->tenantId, (int) ($usage['total_tokens'] ?? 0), 0, 'embedding');

            $data = $response->json('data', []);

            // Map results back to original indices
            $results = array_fill(0, count($texts), null);
            foreach ($data as $item) {
                $batchIndex = $item['index'] ?? null;
                if ($batchIndex !== null && isset($validIndices[$batchIndex])) {
                    $results[$validIndices[$batchIndex]] = $item['embedding'] ?? null;
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('EmbeddingService: Exception', ['message' => $e->getMessage()]);

            return array_fill(0, count($texts), null);
        }
    }

    /**
     * Prepare input text: trim and truncate to max token-safe length.
     */
    protected function prepareInput(string $text): string
    {
        $text = trim($text);
        if (empty($text)) {
            return '';
        }

        // ~8000 chars ≈ ~2000 tokens for Turkish text, well within model limits
        return mb_substr($text, 0, 8000);
    }

    /**
     * Resolve API key: tenant settings → .env fallback.
     */
    protected function resolveApiKey(?int $tenantId): string
    {
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            $key = $tenant?->settings['openai_api_key'] ?? '';
            if (! empty($key)) {
                return $key;
            }
        }

        return config('services.openai.api_key', '');
    }
}
