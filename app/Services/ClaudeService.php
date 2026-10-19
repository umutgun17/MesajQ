<?php

namespace App\Services;

use App\Models\AiTrainingData;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\Ai\AiBudgetGuard;
use App\Services\Ai\ClaudeKeyPolicy;
use App\Support\CargoTracking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;

    /**
     * Marka-NÖTR varsayılan prompt. Marka/politika detayları tenant'ın
     * settings['ai_system_prompt'] ayarından gelir (tüm çağrı noktaları
     * override'ı geçirir); Tonny Black'e özgü eski varsayılan tenant 1'in
     * ayarına taşınmıştır — buraya marka bilgisi YAZMA.
     */
    protected string $systemPrompt = 'Sen bu mağazanın e-ticaret müşteri hizmetleri asistanısın. Müşteri hangi dilde yazdıysa o dilde yanıt ver. Kibar, kısa ve yardımsever ol. Bilmediğin bilgiyi uydurma.

KESİN KURALLAR:
- Kargo ücreti, ödeme seçenekleri, kampanya koşulları gibi mağazaya özgü politikalarda emin değilsen kesin bilgi verme; müşteri temsilcisine yönlendir.
- Müşteriyi mesaj geldiği kanaldan farklı bir satış kanalına yönlendirme.
- Müşteriye hiçbir link, URL veya web adresi VERME. Ürün bilgisi sorulursa sadece metin olarak yanıtla.';

    protected ?array $cachedQueryVector = null;

    public function __construct()
    {
        $this->apiKey = config('services.claude.api_key', '');
        $this->model = config('services.claude.model', 'claude-sonnet-4-5-20250929');
        $this->maxTokens = config('services.claude.max_tokens', 1024);
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * VAPI entegrasyonu için RAG context döner (LLM çağırmaz).
     * Eğitim verisi + bilgi bankasından arama yaparak bağlam metni döner.
     */
    public function buildContextForVapi(string $question, ?int $tenantId = null): string
    {
        $ragContext = $this->buildRagContext($question, null, $tenantId, false);

        // Knowledge base'den de ara
        $kbContext = '';
        $kbResults = KnowledgeBase::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($question) {
                $lower = mb_strtolower($question);
                $q->whereRaw("LOWER(title) LIKE ?", ["%{$lower}%"])
                  ->orWhereRaw("LOWER(content) LIKE ?", ["%{$lower}%"]);
            })
            ->limit(3)
            ->get(['title', 'content']);

        if ($kbResults->isNotEmpty()) {
            $kbParts = $kbResults->map(fn ($kb) => "## {$kb->title}\n{$kb->content}")->toArray();
            $kbContext = "BİLGİ BANKASI:\n" . implode("\n\n", $kbParts);
        }

        $parts = array_filter([$ragContext, $kbContext]);

        return implode("\n\n", $parts);
    }

    /**
     * Resolve API key and model from tenant settings, falling back to .env.
     */
    protected function resolveCredentials(?int $tenantId): array
    {
        $apiKey = $this->apiKey;
        $model = $this->model;

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            $settings = $tenant?->settings ?? [];

            if (! empty($settings['claude_api_key'])) {
                $apiKey = $settings['claude_api_key'];
            }
            if (! empty($settings['ai_model'])) {
                $model = $settings['ai_model'];
            }
        }

        return [$apiKey, $model];
    }

    /**
     * Generate a response using Claude API
     *
     * @return array{content: string, confidence: float, intent: array, entities: array}
     */
    public function generateResponse(string $prompt, array $conversationHistory = [], array $context = [], ?string $channelType = null, ?int $tenantId = null, ?string $systemPromptOverride = null, ?int $conversationId = null, ?int $commentId = null): array
    {
        // Detect intent and extract entities — include recent customer messages for context
        $contextPrompt = $prompt;
        $recentCustomerMessages = array_filter($conversationHistory, fn ($m) => $m['role'] === 'customer');
        if (count($recentCustomerMessages) > 1) {
            $lastMessages = array_slice($recentCustomerMessages, -3);
            $contextPrompt = implode(' ', array_column($lastMessages, 'content'));
        }
        $intentResult = $this->detectIntent($contextPrompt, $channelType);

        if (! $this->isAvailable()) {
            Log::warning('ClaudeService: API not available (no API key)');

            return ['content' => null, 'confidence' => 0.0, 'intent' => $intentResult['intents'], 'entities' => $intentResult['entities']];
        }

        // Bakiye kapısı: kiracının kendi Claude anahtarı varsayılan olarak SADECE
        // chatbot içindir. Gelen kutusu önerisi/otomatik yanıt ancak panelden
        // "Gelen Kutusu AI" açıksa çağrı yapar (bkz. ClaudeKeyPolicy).
        if (! ClaudeKeyPolicy::allows($tenantId, 'inbox')) {
            Log::info('ClaudeService: gelen kutusu AI kapalı, LLM çağrısı atlandı', [
                'tenant_id' => $tenantId,
            ]);

            return ['content' => null, 'confidence' => 0.0, 'intent' => $intentResult['intents'], 'entities' => $intentResult['entities']];
        }

        // Build CRM context from products, orders, customers (build first to inform RAG filtering)
        $crmContext = $this->buildCrmContext($prompt, $conversationId, $commentId, $channelType, $tenantId, $intentResult);

        // Build context from training data and knowledge base (source-aware, tenant-scoped)
        $hasCrmData = ! empty($crmContext);
        $ragContext = $this->buildRagContext($prompt, $channelType, $tenantId, $hasCrmData);

        // Build system prompt — use tenant override if provided
        $system = ! empty($systemPromptOverride) ? $systemPromptOverride : $this->systemPrompt;
        if (! empty($ragContext)) {
            $system .= (! empty($system) ? "\n\n" : '') . "Aşağıdaki bilgileri yanıtında kullanabilirsin:\n" . $ragContext;
        }
        if (! empty($crmContext)) {
            $system .= (! empty($system) ? "\n\n" : '') . "ÖNCELİKLİ VERİ KAYNAĞI — CRM:\nAşağıda gerçek zamanlı CRM verileri var. Bu veriler kesindir.\nKURALLAR:\n- CRM verisi önceliklidir. Fiyat, stok, sipariş durumu ve kargo bilgilerini doğrudan CRM verisinden yanıtla.\n- Sipariş durumunu SADECE CRM verisindeki status_label'dan oku. Durumu asla tahmin etme veya değiştirme.\n- Kargo takip linki CRM verisinde YOKSA müşteriye kargo/takip bilgisi verme. Henüz kargoya verilmemiş demektir.\n- CRM verisinde olmayan bilgiyi uydurma. Eğitim verilerindeki politika ve prosedür bilgileri ile CRM verisini birlikte kullan.\n- Müşteriyi telefon, e-posta veya başka bir platforma yönlendirme.\n\n" . $crmContext;
        }
        if (! empty($context)) {
            $system .= (! empty($system) ? "\n\n" : '') . "Ek bilgi:\n" . implode("\n", $context);
        }

        // Confidence instruction
        $system .= "\n\nYanıtının son satırına aşağıdaki formatta güven skoru ekle:\n[CONFIDENCE: 0.0-1.0]\nSkor kriterleri:\n- 0.9+: CRM verisinden doğrulanmış kesin bilgi (stok, fiyat, sipariş durumu)\n- 0.7-0.9: Eğitim verisinden güçlü eşleşme\n- 0.5-0.7: Genel bilgi, doğrulama yapılamamış\n- 0.5 altı: Emin değilsin, insan temsilci gerekebilir";

        // Build messages array
        $messages = [];

        // Add conversation history (last 10 messages)
        foreach (array_slice($conversationHistory, -10) as $msg) {
            $messages[] = [
                'role' => $msg['role'] === 'customer' ? 'user' : 'assistant',
                'content' => $msg['content'],
            ];
        }

        // Add current prompt as last user message
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        try {
            [$resolvedApiKey, $resolvedModel] = $this->resolveCredentials($tenantId);
            $usingSharedKey = ($resolvedApiKey === $this->apiKey);

            // Harcama freni: paylaşımlı platform anahtarını (veya kiracı limitini) aşan çağrıyı
            // yapma — AI kullanılamıyormuş gibi davran (fallback devreye girer), hata döndürme.
            if (! AiBudgetGuard::allows($tenantId, $usingSharedKey)) {
                Log::warning('ClaudeService: AI budget exceeded, skipping LLM call', [
                    'tenant_id' => $tenantId,
                    'shared_key' => $usingSharedKey,
                ]);

                return ['content' => null, 'confidence' => 0.0, 'intent' => $intentResult['intents'], 'entities' => $intentResult['entities']];
            }

            $response = Http::withHeaders([
                'x-api-key' => $resolvedApiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $resolvedModel,
                'max_tokens' => $this->maxTokens,
                'system' => $system,
                'messages' => $messages,
            ]);

            if ($response->failed()) {
                Log::error('ClaudeService: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return ['content' => null, 'confidence' => 0.0, 'intent' => $intentResult['intents'], 'entities' => $intentResult['entities']];
            }

            // Gerçek token kullanımını harcama sayacına işle
            $usage = $response->json('usage', []);
            AiBudgetGuard::record(
                $tenantId,
                (int) ($usage['input_tokens'] ?? 0),
                (int) ($usage['output_tokens'] ?? 0),
                'claude'
            );

            $content = $response->json('content.0.text', '');

            if (empty($content)) {
                return ['content' => null, 'confidence' => 0.0, 'intent' => $intentResult['intents'], 'entities' => $intentResult['entities']];
            }

            // Parse confidence score from response
            $confidence = 0.5;
            if (preg_match('/\[CONFIDENCE:\s*([\d.]+)\s*\]/i', $content, $m)) {
                $confidence = min(1.0, max(0.0, (float) $m[1]));
                $content = trim(str_replace($m[0], '', $content));
            } else {
                Log::debug('ClaudeService: Confidence tag not found in response', [
                    'content_tail' => mb_substr($content, -80),
                ]);
            }

            // Post-processing: enforce critical policies
            [$content, $confidence] = $this->enforceCriticalPolicies($content, $confidence, $prompt, $channelType, $tenantId);

            return [
                'content' => $content,
                'confidence' => $confidence,
                'intent' => $intentResult['intents'],
                'entities' => $intentResult['entities'],
            ];
        } catch (\Exception $e) {
            Log::error('ClaudeService: Exception', ['message' => $e->getMessage()]);

            return ['content' => null, 'confidence' => 0.0, 'intent' => $intentResult['intents'], 'entities' => $intentResult['entities']];
        }
    }

    /**
     * Build RAG context — exact semantic search for 100% recall.
     * Source-aware: prioritize training data matching the channel type first.
     * Tenant-scoped: only include data belonging to the given tenant.
     */
    protected function buildRagContext(string $prompt, ?string $channelType = null, ?int $tenantId = null, bool $hasCrmData = false): string
    {
        $embeddingService = EmbeddingService::forTenant($tenantId);
        $queryVector = null;

        if ($embeddingService->isAvailable()) {
            $queryVector = $embeddingService->embed($prompt);
            $this->cachedQueryVector = $queryVector;
        }

        if ($queryVector) {
            return $this->buildHybridRagContext($prompt, $queryVector, $channelType, $tenantId, $hasCrmData);
        }

        return $this->buildKeywordRagContext($prompt, $channelType, $tenantId, $hasCrmData);
    }

    /**
     * Hybrid RAG — exact semantic search + keyword search merged for best results.
     */
    protected function buildHybridRagContext(string $prompt, array $queryVector, ?string $channelType, ?int $tenantId, bool $hasCrmData): string
    {
        // ── Source 1: Exact semantic search (top-15) ──
        $vectorStr = '[' . implode(',', $queryVector) . ']';

        DB::statement('SET enable_indexscan = off');

        $params = [$vectorStr];
        $tenantClause = $tenantId ? 'AND tenant_id = ?' : '';
        if ($tenantId) {
            $params[] = $tenantId;
        }

        $semanticRows = DB::select("
            SELECT id, question, answer, channel_type, source_type, quality_score,
                   1 - (embedding <=> ?::vector) AS similarity
            FROM ai_training_data
            WHERE is_active = true AND embedding IS NOT NULL
              {$tenantClause}
            ORDER BY embedding <=> ?::vector
            LIMIT 15
        ", array_merge($params, [$vectorStr]));

        DB::statement('SET enable_indexscan = on');

        // Index semantic results by ID
        $candidates = [];
        foreach ($semanticRows as $row) {
            if ($row->similarity < 0.25) {
                continue;
            }
            $candidates[$row->id] = (object) [
                'id' => $row->id,
                'question' => $row->question,
                'answer' => $row->answer,
                'channel_type' => $row->channel_type,
                'source_type' => $row->source_type,
                'quality_score' => $row->quality_score,
                'semantic_score' => $row->similarity,
                'keyword_score' => 0,
                'hybrid_score' => 0,
            ];
        }

        // ── Source 2: Keyword search (top-10) ──
        $words = array_filter(
            explode(' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $prompt))),
            fn ($w) => mb_strlen($w) > 2
        );

        if (! empty($words)) {
            $scoreExpr = $this->buildMatchScoreExpr($words, 'question');
            $wordCount = count($words);
            $minMatches = $wordCount >= 3 ? 2 : 1;

            $keywordQuery = AiTrainingData::withoutGlobalScopes()
                ->where('is_active', true)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('question', 'ilike', "%{$word}%");
                    }
                })
                ->selectRaw('id, question, answer, channel_type, source_type, quality_score, (' . $scoreExpr['sql'] . ') as match_score', $scoreExpr['bindings'])
                ->whereRaw('(' . $scoreExpr['sql'] . ') >= ?', array_merge($scoreExpr['bindings'], [$minMatches]))
                ->orderByRaw('(' . $scoreExpr['sql'] . ') DESC', $scoreExpr['bindings'])
                ->limit(10)
                ->get();

            foreach ($keywordQuery as $row) {
                $normalizedKeyword = $wordCount > 0 ? $row->match_score / $wordCount : 0;

                if (isset($candidates[$row->id])) {
                    // Already found by semantic — add keyword score
                    $candidates[$row->id]->keyword_score = $normalizedKeyword;
                } else {
                    // New candidate from keyword only
                    $candidates[$row->id] = (object) [
                        'id' => $row->id,
                        'question' => $row->question,
                        'answer' => $row->answer,
                        'channel_type' => $row->channel_type,
                        'source_type' => $row->source_type,
                        'quality_score' => $row->quality_score,
                        'semantic_score' => 0,
                        'keyword_score' => $normalizedKeyword,
                        'hybrid_score' => 0,
                    ];
                }
            }
        }

        // ── Merge: compute hybrid score ──
        // Semantic weight: 0.8, Keyword weight: 0.2, Overlap bonus: +0.1
        foreach ($candidates as $c) {
            $c->hybrid_score = ($c->semantic_score * 0.8) + ($c->keyword_score * 0.2);
            if ($c->semantic_score > 0 && $c->keyword_score > 0) {
                $c->hybrid_score += 0.1; // Both sources agree — boost
            }
        }

        // Sort by hybrid score descending
        $sorted = collect($candidates)->sortByDesc('hybrid_score')->values();

        // ── Output: channel-type priority ──
        $parts = [];
        $usedIds = [];

        // Step 1: Same channel type — max 3
        if ($channelType) {
            foreach ($sorted as $row) {
                if ($row->channel_type !== $channelType) {
                    continue;
                }
                if ($hasCrmData && $this->isDeflectionAnswer($row->answer)) {
                    continue;
                }
                if (count($usedIds) >= 3) {
                    break;
                }
                $data = new AiTrainingData;
                $data->id = $row->id;
                $data->channel_type = $row->channel_type;
                $data->source_type = $row->source_type;
                $sourceLabel = $this->getSourceLabel($data);
                $tag = $this->hybridTag($row);
                $parts[] = "[{$sourceLabel} | {$tag}]\nS: {$row->question}\nC: {$row->answer}";
                $usedIds[] = $row->id;
            }
        }

        // Step 2: Cross-source — fill up to 7 total
        foreach ($sorted as $row) {
            if (count($parts) >= 7) {
                break;
            }
            if (in_array($row->id, $usedIds)) {
                continue;
            }
            if ($hasCrmData && $this->isDeflectionAnswer($row->answer)) {
                continue;
            }
            $data = new AiTrainingData;
            $data->id = $row->id;
            $data->channel_type = $row->channel_type;
            $data->source_type = $row->source_type;
            $sourceLabel = $this->getSourceLabel($data);
            $tag = $this->hybridTag($row);
            $parts[] = "[{$sourceLabel} | {$tag}]\nS: {$row->question}\nC: {$row->answer}";
            $usedIds[] = $row->id;
        }

        // ── Product catalog search — only when CRM didn't provide product context ──
        if (! $hasCrmData) {
            $productResults = $this->searchTopProducts($queryVector, $tenantId, 3);
            foreach ($productResults as $pRow) {
                $product = Product::withoutGlobalScopes()
                    ->with([
                        'variants' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true)->orderBy('sku'),
                        'reviewSummary' => fn ($q) => $q->withoutGlobalScopes(),
                    ])
                    ->find($pRow->id);

                if (! $product) {
                    continue;
                }

                $sim = round($pRow->similarity, 2);
                $pLines = ["[Ürün Kataloğu | benzerlik: {$sim}]"];
                $pLines[] = "Ürün: {$product->name} ({$product->sku})";

                if ($product->url) {
                    $pLines[] = "URL: {$product->url}";
                }

                $price = $product->discounted_price ?: $product->price;
                if ($price) {
                    $pLines[] = 'Fiyat: ' . number_format($price, 2, ',', '.') . ' TL';
                }

                if ($product->variants->isNotEmpty()) {
                    $sizes = $product->variants->map(function ($v) {
                        $attrs = $v->attributes ?? [];
                        $label = $attrs['size'] ?? $attrs['Beden'] ?? $attrs['beden'] ?? $v->sku;
                        $stock = (int) $v->stock_quantity;

                        return $stock > 0 ? "{$label}(mevcut)" : "{$label}(TÜKENDİ)";
                    })->implode(', ');
                    $pLines[] = "Bedenler: {$sizes}";
                }

                $summary = $product->reviewSummary;
                if ($summary && $summary->rate_count > 0) {
                    $pLines[] = "Puan: {$summary->avg_rate}/5 ({$summary->rate_count} değerlendirme)";
                }

                $parts[] = implode("\n", $pLines);

                // ── Sibling products (same model, different colors) ──
                $skuParts = explode('|', $product->sku);
                if (count($skuParts) >= 2) {
                    $modelBase = $skuParts[0] . '|' . $skuParts[1] . '|';
                    $siblings = Product::withoutGlobalScopes()
                        ->where('id', '<>', $product->id)
                        ->where('sku', 'like', $modelBase . '%')
                        ->where('is_active', true)
                        ->where('tenant_id', $tenantId)
                        ->with(['variants' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true)->where('stock_quantity', '>', 0)])
                        ->get();

                    // Sort by total stock descending, limit to top 5
                    $siblings = $siblings->filter(fn ($s) => $s->variants->isNotEmpty())
                        ->sortByDesc(fn ($s) => $s->variants->sum('stock_quantity'))
                        ->take(5);

                    $siblingLines = [];
                    foreach ($siblings as $sib) {
                        $color = $sib->variants->first()?->attributes['Renk'] ?? $sib->variants->first()?->attributes['renk'] ?? null;
                        if (! $color) {
                            $color = str_replace($skuParts[1], '', $sib->name);
                        }
                        $sibSizes = $sib->variants->map(function ($v) {
                            $beden = $v->attributes['Beden'] ?? $v->attributes['beden'] ?? $v->attributes['size'] ?? '?';
                            return $beden;
                        })->implode(', ');
                        $siblingLines[] = "  {$color}: beden {$sibSizes} mevcut" . ($sib->url ? " | {$sib->url}" : '');
                    }

                    if (! empty($siblingLines)) {
                        $parts[] = "[Aynı Modelin Diğer Renkleri - stokta olan bedenler]\n" . implode("\n", $siblingLines);
                    }
                }
            }
        }

        return implode("\n\n", $parts);
    }

    protected function hybridTag(object $row): string
    {
        $s = round($row->semantic_score, 2);
        $k = round($row->keyword_score, 2);
        $h = round($row->hybrid_score, 2);
        $src = ($s > 0 && $k > 0) ? 'both' : ($s > 0 ? 'sem' : 'kw');

        return "h:{$h} s:{$s} k:{$k} [{$src}]";
    }

    /**
     * Original keyword-based RAG context (ILIKE fallback).
     */
    protected function buildKeywordRagContext(string $prompt, ?string $channelType = null, ?int $tenantId = null, bool $hasCrmData = false): string
    {
        $words = array_filter(
            explode(' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $prompt))),
            fn ($w) => mb_strlen($w) > 2
        );

        if (empty($words)) {
            return '';
        }

        $parts = [];
        $usedIds = [];

        // Step 1: Search training data matching the same channel type first (source-aware)
        if ($channelType) {
            $query = AiTrainingData::withoutGlobalScopes()
                ->where('is_active', true)
                ->where('channel_type', $channelType)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

            $scoreExpr = $this->buildMatchScoreExpr($words, 'question');

            // For 3+ words, require at least 2 word matches
            if (count($words) >= 3) {
                $query->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('question', 'ilike', "%{$word}%");
                    }
                });
                $query->selectRaw('*, (' . $scoreExpr['sql'] . ') as match_score', $scoreExpr['bindings']);
                $query->whereRaw('(' . $scoreExpr['sql'] . ') >= 2', $scoreExpr['bindings']);
                $query->orderByRaw('(' . $scoreExpr['sql'] . ') DESC', $scoreExpr['bindings']);
            } else {
                $query->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('question', 'ilike', "%{$word}%");
                    }
                });
            }

            $sameSource = $query
                ->orderByDesc('quality_score')
                ->orderByDesc('created_at')
                ->limit(3)
                ->get();

            foreach ($sameSource as $t) {
                if ($hasCrmData && $this->isDeflectionAnswer($t->answer)) {
                    continue;
                }
                $sourceLabel = $this->getSourceLabel($t);
                $parts[] = "[{$sourceLabel}]\nS: {$t->question}\nC: {$t->answer}";
                $usedIds[] = $t->id;
            }
        }

        // Step 2: Fill remaining slots with other training data (cross-source)
        $remaining = 7 - count($usedIds);
        if ($remaining > 0) {
            $query = AiTrainingData::withoutGlobalScopes()
                ->where('is_active', true)
                ->when(! empty($usedIds), fn ($q) => $q->whereNotIn('id', $usedIds))
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

            $scoreExpr = $this->buildMatchScoreExpr($words, 'question');

            if (count($words) >= 3) {
                $query->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('question', 'ilike', "%{$word}%");
                    }
                });
                $query->selectRaw('*, (' . $scoreExpr['sql'] . ') as match_score', $scoreExpr['bindings']);
                $query->whereRaw('(' . $scoreExpr['sql'] . ') >= 2', $scoreExpr['bindings']);
                $query->orderByRaw('(' . $scoreExpr['sql'] . ') DESC', $scoreExpr['bindings']);
            } else {
                $query->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('question', 'ilike', "%{$word}%");
                    }
                });
            }

            $otherTraining = $query
                ->orderByDesc('quality_score')
                ->orderByDesc('created_at')
                ->limit(min($remaining, 4))
                ->get();

            foreach ($otherTraining as $t) {
                if ($hasCrmData && $this->isDeflectionAnswer($t->answer)) {
                    continue;
                }
                $sourceLabel = $this->getSourceLabel($t);
                $parts[] = "[{$sourceLabel}]\nS: {$t->question}\nC: {$t->answer}";
            }
        }

        // Step 3: Search knowledge base
        $kb = KnowledgeBase::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('title', 'ilike', "%{$word}%")
                        ->orWhere('content', 'ilike', "%{$word}%");
                }
            })
            ->limit(2)
            ->get();

        foreach ($kb as $k) {
            $parts[] = "[Bilgi Bankası]\nBilgi: {$k->title}\n{$k->content}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get human-readable source label for a training data entry
     */
    protected function getSourceLabel(AiTrainingData $data): string
    {
        return match ($data->channel_type) {
            'trendyol-product' => 'Trendyol Ürün Sorusu',
            'trendyol-order' => 'Trendyol Sipariş Sorusu',
            'hepsiburada-product' => 'Hepsiburada Ürün Sorusu',
            'ticimax-ticket' => 'Ticimax Destek Talebi',
            'instagram-message' => 'Instagram DM',
            'instagram-comment' => 'Instagram Yorum',
            'facebook-message' => 'Facebook Mesaj',
            'facebook-comment' => 'Facebook Yorum',
            'whatsapp' => 'WhatsApp Mesaj',
            'email' => 'E-posta',
            default => match ($data->source_type) {
                'manual' => 'Manuel Giriş',
                'conversation' => 'Müşteri Konuşması',
                default => 'Genel',
            },
        };
    }

    /**
     * Post-processing: enforce critical business policies.
     * Catches AI hallucinations on known policy topics and replaces with correct answers.
     *
     * @return array{0: string, 1: float} [corrected content, corrected confidence]
     */
    protected function enforceCriticalPolicies(string $content, float $confidence, string $prompt, ?string $channelType = null, ?int $tenantId = null): array
    {
        $lower = mb_strtolower($content);

        // ── 1) Strip URLs/links from response ──
        // AI should never include URLs, links, or bare domain names in responses
        $hasUrl = preg_match('/https?:\/\//i', $content);
        $hasDomain = preg_match('/(?:www\.)?tonnyblack\.com/i', $content);
        if ($hasUrl || $hasDomain) {
            Log::info('ClaudeService: URLs/domains stripped from response');
            // Remove full URLs
            $content = preg_replace('/https?:\/\/[^\s,)}\]]+/i', '', $content);
            // Remove bare domain references like www.tonnyblack.com.tr or tonnyblack.com/path
            // Negatif lookbehind: e-posta adreslerinin (info@tonnyblack.com.tr) domain kısmını koru, sadece çıplak domain/URL sil
            $content = preg_replace('/(?<![\w@.])(?:www\.)?tonnyblack\.com(?:\.tr)?[^\s]*/i', '', $content);
            // Clean up leftover artifacts (double spaces, empty parentheses, trailing colons)
            $content = preg_replace('/\(\s*\)/', '', $content);
            $content = preg_replace('/:\s*$/m', '.', $content);
            $content = preg_replace('/  +/', ' ', $content);
            $content = trim($content);
            $lower = mb_strtolower($content);
        }

        // ── 2) Topic-based policy checks ──
        // Bu düzeltme cevapları Tonny Black'in iş kurallarıdır (kapıda ödeme yok,
        // fiziksel mağaza yok, 1+1 kampanya). Başka tenant'ın cevabını bu
        // kurallarla EZMEK yanlış bilgi üretir → yalnız tenant 1'e uygulanır.
        if ($tenantId !== 1) {
            return [$content, $confidence];
        }

        $policies = [
            [
                'topic' => ['kapıda ödeme', 'kapıda odeme', 'kapida ödeme', 'kapida odeme'],
                'violation' => ['mevcuttur', 'mevcut ', 'seçebilirsiniz', 'yapabilirsiniz', 'imkanı var', 'imkani var', 'seçeneğimiz var', 'secenegimiz var', 'evet,', 'evet '],
                'exclude' => ['mevcut değil', 'mevcut degil', 'yoktur', 'yok ', 'bulunmamaktadır', 'bulunmamaktadir', 'maalesef'],
                'answer' => 'Merhaba, maalesef kapıda ödeme seçeneğimiz mevcut değildir. Sadece kredi kartı veya banka kartı ile online ödeme yapılabilmektedir.',
            ],
            [
                'topic' => ['fiziksel mağaza', 'fiziksel magaza', 'mağazanız', 'magazaniz', 'mağaza adres', 'magaza adres', 'showroom'],
                'violation' => ['bulunmaktadır', 'mevcuttur', 'adresimiz', 'ziyaret edebilir', 'gelebilirsiniz'],
                'exclude' => ['bulunmamaktadır', 'mevcut değil', 'yoktur', 'yok ', 'trendyol'],
                'answer' => 'Merhaba, maalesef fiziksel satış mağazamız bulunmamaktadır. Sadece online satış yapmaktayız.',
            ],
            [
                'topic' => ['1 alana 1 bedava', '1 al 1 bedava', 'bir alana bir bedava', 'kampanya'],
                'violation' => ['aynı modelden olmalı', 'aynı üründen olmalı', 'aynı modelden seçme', 'aynı üründen seçme', 'aynı modelden ekle', 'aynı üründen bir tane daha', 'iki ürün de aynı', 'ikisi de aynı model'],
                'exclude' => ['zorunda değil', 'zorunda degil', 'farklı ürün', 'farkli ürün', 'istediğiniz', 'istediginiz'],
                'answer' => 'Merhaba, 1 Alana 1 Bedava kampanyamızda aynı üründen almak zorunda değilsiniz! Kampanya kapsamındaki ürünlerden istediğiniz 2 farklı ürünü, farklı renk ve farklı numarada seçebilirsiniz. Sepete 2 kampanya ürünü eklemeniz yeterli, sistem otomatik olarak indirimli olanı bedava hesaplar.',
            ],
        ];

        foreach ($policies as $policy) {
            $topicFound = false;
            foreach ($policy['topic'] as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $topicFound = true;
                    break;
                }
            }
            if (! $topicFound) continue;

            $alreadyCorrect = false;
            foreach ($policy['exclude'] as $ok) {
                if (str_contains($lower, $ok)) {
                    $alreadyCorrect = true;
                    break;
                }
            }
            if ($alreadyCorrect) continue;

            foreach ($policy['violation'] as $bad) {
                if (str_contains($lower, $bad)) {
                    Log::warning('ClaudeService: Critical policy violation corrected', [
                        'topic' => $policy['topic'][0],
                        'violation' => $bad,
                        'original' => mb_substr($content, 0, 200),
                    ]);

                    return [$policy['answer'], 0.95];
                }
            }
        }

        return [$content, $confidence];
    }

    /**
     * Fallback: keyword matching (same logic as original AiController)
     */
    protected function fallbackKeywordMatch(string $prompt, ?string $channelType = null, ?int $tenantId = null): string
    {
        $words = array_filter(
            explode(' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $prompt))),
            fn ($w) => mb_strlen($w) > 2
        );

        if (empty($words)) {
            return 'Merhaba, mesajınız için teşekkür ederiz. Konuyla ilgili en kısa sürede size yardımcı olacağız.';
        }

        // Training data — prioritize same channel, require 2+ word matches to reduce false positives
        $scoreExpr = $this->buildMatchScoreExpr($words, 'question');
        $minMatches = count($words) >= 2 ? 2 : 1;

        $query = AiTrainingData::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('question', 'ilike', "%{$word}%");
                }
            })
            ->selectRaw('*, (' . $scoreExpr['sql'] . ') as match_score', $scoreExpr['bindings'])
            ->whereRaw('(' . $scoreExpr['sql'] . ') >= ?', array_merge($scoreExpr['bindings'], [$minMatches]));

        // Prioritize same channel type
        if ($channelType) {
            $query->orderByRaw('CASE WHEN channel_type = ? THEN 0 ELSE 1 END', [$channelType]);
        }

        $match = $query->orderByRaw('(' . $scoreExpr['sql'] . ') DESC', $scoreExpr['bindings'])
            ->orderByDesc('quality_score')
            ->orderByDesc('created_at')
            ->first();

        if ($match) {
            $match->increment('used_count');

            return $match->answer;
        }

        // Knowledge base
        $kb = KnowledgeBase::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('title', 'ilike', "%{$word}%")
                        ->orWhere('content', 'ilike', "%{$word}%");
                }
            })
            ->first();

        if ($kb) {
            return $kb->content;
        }

        return 'Merhaba, mesajınız için teşekkür ederiz. Konuyla ilgili en kısa sürede size yardımcı olacağız. Lütfen beklemede kalın.';
    }

    /**
     * Check if a training data answer is a deflection (redirects to phone/website without real info).
     * These are filtered out when CRM data is available.
     */
    protected function isDeflectionAnswer(string $answer): bool
    {
        $lower = mb_strtolower($answer);
        $deflectionPatterns = [
            'iletişime geçebilirseniz',
            'iletişime geçebilir',
            'müşteri hizmetleri',
            'santral hattı',
            'bizi arayabilir',
            'numaralı hattımız',
            'bizimle iletişime',
            'destek hattımız',
            'whatsapp hattımız',
            'whatsapp üzerinden',
            'tarafımıza ulaşabilirsiniz',
            'bize ulaşabilirsiniz',
            'iletişim bilgilerimiz',
        ];
        $hasDeflection = false;
        foreach ($deflectionPatterns as $pattern) {
            if (mb_strpos($lower, $pattern) !== false) {
                $hasDeflection = true;
                break;
            }
        }
        if (! $hasDeflection) {
            return false;
        }

        // Only filter if the answer is short/generic (no real info beyond the redirect)
        return mb_strlen($answer) < 200;
    }

    /**
     * Build a CASE WHEN match score SQL expression for a given column and word list.
     * Returns ['sql' => '...', 'bindings' => [...]]
     */
    protected function buildMatchScoreExpr(array $words, string $column): array
    {
        $parts = [];
        $bindings = [];
        foreach ($words as $word) {
            $parts[] = "CASE WHEN LOWER({$column}) LIKE ? THEN 1 ELSE 0 END";
            $bindings[] = '%' . $word . '%';
        }

        return [
            'sql' => implode(' + ', $parts),
            'bindings' => $bindings,
        ];
    }

    // ── Intent Detection & Entity Extraction ──

    /**
     * Detect intents and extract entities from prompt
     *
     * @return array{intents: array, entities: array}
     */
    protected function detectIntent(string $prompt, ?string $channelType = null): array
    {
        $lower = mb_strtolower($prompt);
        $intents = [];
        $entities = [];

        // ── Keyword-based intent detection ──

        $productKeywords = [
            'stok', 'beden', 'numara', 'fiyat', 'renk', 'ürün', 'boyut', 'ölçü',
            'kaç tl', 'indirim', 'var mı', 'mevcut', 'kalıp', 'kumaş', 'özellik',
            'yorum', 'puan', 'malzeme', 'kargo ücreti', 'bedel',
            'ayakkabı', 'ceket', 'pantolon', 'gömlek', 'elbise', 'çanta', 'mont',
            'bot', 'sneaker', 'spor', 'klasik', 'deri', 'keten', 'jean', 'kampanya',
            'alternatif', 'tavsiye', 'kaldı mı', 'tükendi', 'bitti', 'gelecek mi',
            'ucuz', 'model', 'kaç lira',
        ];

        $orderKeywords = [
            'sipariş', 'kargo', 'teslimat', 'iade', 'iptal', 'takip', 'nerede',
            'ne zaman', 'gelecek', 'durum', 'paket', 'fatura', 'ödeme', 'değişim',
            'hasar', 'teslim', 'gönderi', 'siparişim', 'kargom', 'gönderildi',
            'ulaştı', 'teslim edildi', 'kayıp', 'kırık', 'defolu', 'geri gönderme',
            'geri ödeme', 'iade süreci', 'para iadesi', 'hes kodu',
        ];

        $generalKeywords = [
            'müşteri hizmetleri', 'şikâyet', 'şikayet', 'çalışma saatleri', 'mağaza',
            'adres', 'iletişim', 'garanti', 'üyelik', 'hesap', 'şifre',
        ];

        foreach ($productKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                $intents[] = 'product';
                break;
            }
        }

        foreach ($orderKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                $intents[] = 'order';
                break;
            }
        }

        foreach ($generalKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                $intents[] = 'general';
                break;
            }
        }

        // ── Entity extraction ──

        // Order number: TB + 10 digits (Ticimax format) or 6+ pure digits
        if (preg_match('/\b(TB\d{10})\b/i', $prompt, $m)) {
            $entities['order_no'] = strtoupper($m[1]);
        } elseif (preg_match('/\b(\d{6,})\b/', $prompt, $m)) {
            // Pure digits, distinguish from barcode (13 digits)
            if (strlen($m[1]) < 13) {
                $entities['order_no'] = $m[1];
            }
        }

        // Product code (TB prefix but NOT order format TB+10digits)
        if (preg_match('/\b(TB[A-Z]*\d{3,}[-\d]*)\b/i', $prompt, $m)) {
            $candidate = strtoupper($m[1]);
            // Skip if already matched as order_no
            if (! isset($entities['order_no']) || $entities['order_no'] !== $candidate) {
                $entities['product_code'] = $candidate;
            }
        }

        // Barcode (exactly 13 digits)
        if (preg_match('/\b(\d{13})\b/', $prompt, $m)) {
            $entities['barcode'] = $m[1];
        }

        // Size/beden
        if (preg_match('/\b(\d{2,3}|XXS|XS|S|M|L|XL|XXL|XXXL)\s*(beden|numara|no)\b/iu', $prompt, $m)) {
            $entities['size'] = strtoupper($m[1]);
        }

        // Color (Turkish color list)
        $colors = [
            'siyah', 'beyaz', 'kırmızı', 'mavi', 'yeşil', 'kahverengi', 'gri',
            'lacivert', 'bordo', 'bej', 'krem', 'pembe', 'turuncu', 'mor',
            'haki', 'ekru', 'antrasit',
        ];
        foreach ($colors as $color) {
            if (mb_strpos($lower, $color) !== false) {
                $entities['color'] = $color;
                break;
            }
        }

        // ── Entity-driven intent inference ──
        if (! empty($entities['order_no']) && ! in_array('order', $intents)) {
            $intents[] = 'order';
        }
        if ((! empty($entities['product_code']) || ! empty($entities['barcode'])) && ! in_array('product', $intents)) {
            $intents[] = 'product';
        }
        if ((! empty($entities['size']) || ! empty($entities['color'])) && ! in_array('product', $intents)) {
            $intents[] = 'product';
        }

        // ── Channel-aware default intents ──
        $channelDefaults = [
            'trendyol-product' => ['product'],
            'hepsiburada-product' => ['product'],
            'ticimax-ticket' => ['order', 'general'],
        ];

        if ($channelType && isset($channelDefaults[$channelType])) {
            $intents = array_values(array_unique(array_merge($intents, $channelDefaults[$channelType])));
        }

        return [
            'intents' => $intents,
            'entities' => $entities,
        ];
    }

    // ── CRM Context ──

    protected function buildCrmContext(string $prompt, ?int $conversationId, ?int $commentId, ?string $channelType, ?int $tenantId, array $intentResult): string
    {
        $intents = $intentResult['intents'] ?? [];
        if (empty($intents)) {
            return '';
        }

        // Load conversation for metadata and customer
        $conversation = null;
        if ($conversationId) {
            $conversation = Conversation::withoutGlobalScopes()
                ->with('customer')
                ->find($conversationId);
        } elseif ($commentId) {
            $comment = \App\Models\Comment::withoutGlobalScopes()->find($commentId);
            if ($comment?->conversation_id) {
                $conversation = Conversation::withoutGlobalScopes()
                    ->with('customer')
                    ->find($comment->conversation_id);
            }
        }

        $parts = [];

        // Always try product context — semantic threshold (0.35) prevents false positives
        $productContext = $this->resolveProductContext($prompt, $conversation, $channelType, $tenantId, $intentResult['entities'] ?? []);
        if ($productContext) {
            $parts[] = $productContext;
        }

        if (in_array('order', $intents) || in_array('general', $intents)) {
            $orderContext = $this->resolveOrderContext($prompt, $conversation, $tenantId, $intentResult['entities'] ?? []);
            if ($orderContext) {
                $parts[] = $orderContext;
            }
        }

        if (empty($parts)) {
            return '';
        }

        return "Aşağıdaki CRM verilerini yanıtında kullan. Bu veriler gerçek ve günceldir:\n\n" . implode("\n\n", $parts);
    }

    protected function resolveProductContext(string $prompt, ?Conversation $conversation, ?string $channelType, ?int $tenantId, array $entities = []): ?string
    {
        $product = null;

        // Strategy 1: Platform metadata (Trendyol / Hepsiburada)
        if ($conversation && $channelType === 'trendyol-product') {
            $mainId = $conversation->metadata['product_main_id'] ?? null;
            if ($mainId) {
                $product = Product::withoutGlobalScopes()
                    ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('sku', 'ilike', '%' . $mainId . '%')
                    ->first();
            }
        } elseif ($conversation && $channelType === 'hepsiburada-product') {
            $sku = $conversation->metadata['product_sku'] ?? null;
            if ($sku) {
                $product = Product::withoutGlobalScopes()
                    ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where(fn ($q) => $q->where('sku', (string) $sku)->orWhere('sku', 'ilike', '%' . $sku . '%'))
                    ->first();
            }
        }

        // Strategy 2: Product code from entities (TB prefix)
        if (! $product && ! empty($entities['product_code'])) {
            $code = $entities['product_code'];
            $product = Product::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where(fn ($q) => $q->where('sku', 'ilike', '%' . $code . '%')->orWhere('name', 'ilike', '%' . $code . '%'))
                ->first();
        }

        // Strategy 3: Barcode from entities → variant → product
        if (! $product && ! empty($entities['barcode'])) {
            $variant = ProductVariant::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('barcode', $entities['barcode'])
                ->first();
            if ($variant) {
                $product = Product::withoutGlobalScopes()->find($variant->product_id);
            }
        }

        // Strategy 4: Name search for other channels (Meta, WhatsApp, Email, etc.)
        if (! $product) {
            $product = $this->searchProductByName($prompt, $tenantId);
        }

        if (! $product) {
            return null;
        }

        return $this->formatProductContext($product, $entities, $channelType);
    }

    protected function searchProductByName(string $prompt, ?int $tenantId): ?Product
    {
        // Reuse cached vector from RAG context (avoids duplicate OpenAI API call)
        $queryVector = $this->cachedQueryVector;
        if (! $queryVector) {
            $embeddingService = EmbeddingService::forTenant($tenantId);
            if ($embeddingService->isAvailable()) {
                $queryVector = $embeddingService->embed($prompt);
            }
        }

        if ($queryVector) {
            $product = $this->searchProductBySemantic($queryVector, $tenantId);
            if ($product) {
                return $product;
            }
        }

        // Fallback to keyword search
        return $this->searchProductByKeyword($prompt, $tenantId);
    }

    protected function searchProductBySemantic(array $queryVector, ?int $tenantId): ?Product
    {
        $vectorStr = '[' . implode(',', $queryVector) . ']';

        $params = [$vectorStr];
        $tenantClause = '';
        if ($tenantId) {
            $tenantClause = 'AND tenant_id = ?';
            $params[] = $tenantId;
        }

        // Exact search for 100% accuracy (6K products ≈ 100ms)
        DB::statement('SET enable_indexscan = off');

        $rows = DB::select("
            SELECT id, 1 - (embedding <=> ?::vector) AS similarity
            FROM products
            WHERE is_active = true AND embedding IS NOT NULL
              {$tenantClause}
            ORDER BY embedding <=> ?::vector
            LIMIT 1
        ", array_merge($params, [$vectorStr]));

        DB::statement('SET enable_indexscan = on');

        if (empty($rows) || $rows[0]->similarity < 0.35) {
            return null;
        }

        return Product::withoutGlobalScopes()->find($rows[0]->id);
    }

    /**
     * Search top N products by semantic similarity for RAG catalog context.
     * Uses cachedQueryVector to avoid extra OpenAI calls.
     */
    protected function searchTopProducts(array $queryVector, ?int $tenantId, int $limit = 3): array
    {
        $vectorStr = '[' . implode(',', $queryVector) . ']';

        $params = [$vectorStr];
        $tenantClause = '';
        if ($tenantId) {
            $tenantClause = 'AND tenant_id = ?';
            $params[] = $tenantId;
        }

        DB::statement('SET enable_indexscan = off');

        $rows = DB::select("
            SELECT id, name, sku, price, discounted_price, url,
                   1 - (embedding <=> ?::vector) AS similarity
            FROM products
            WHERE is_active = true AND embedding IS NOT NULL
              {$tenantClause}
            ORDER BY embedding <=> ?::vector
            LIMIT ?
        ", array_merge($params, [$vectorStr, $limit]));

        DB::statement('SET enable_indexscan = on');

        // Filter by minimum threshold
        $results = [];
        foreach ($rows as $row) {
            if ($row->similarity < 0.35) {
                continue;
            }
            $results[] = $row;
        }

        return $results;
    }

    protected function searchProductByKeyword(string $prompt, ?int $tenantId): ?Product
    {
        // Turkish stopwords
        $stopwords = [
            'bir', 'ile', 'için', 'bu', 'şu', 'ben', 'sen', 'biz', 'siz',
            'merhaba', 'selam', 'iyi', 'lütfen', 'acaba', 'rica', 'ederim',
            'var', 'yok', 'kaç', 'nasıl', 'neden', 'nerede', 'hangi', 'ama',
            'veya', 'hem', 'daha', 'çok', 'olan', 'olarak', 'gibi', 'sonra',
            'önce', 'arasında', 'hakkında', 'olup', 'olan', 'olduğu', 'olur',
            'teşekkür', 'ederiz', 'teşekkürler', 'günaydın', 'hayırlı',
            'stok', 'beden', 'numara', 'fiyat', 'renk', 'ürün', 'boyut',
            'istiyorum', 'bakıyorum', 'arıyorum', 'bakar', 'mısınız',
        ];

        $cleaned = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $prompt));
        $words = array_filter(
            explode(' ', $cleaned),
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, $stopwords)
        );

        if (empty($words)) {
            return null;
        }

        // Search products by name — score by match count
        $query = Product::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true);

        $scoreExpr = $this->buildMatchScoreExpr($words, 'name');

        $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('name', 'ilike', '%' . $word . '%');
            }
        });

        $query->selectRaw('*, (' . $scoreExpr['sql'] . ') as match_score', $scoreExpr['bindings']);

        // Require at least 2 word matches to reduce false positives
        if (count($words) >= 2) {
            $query->whereRaw('(' . $scoreExpr['sql'] . ') >= 2', $scoreExpr['bindings']);
        }

        $query->orderByRaw('(' . $scoreExpr['sql'] . ') DESC', $scoreExpr['bindings']);
        $query->limit(1);

        return $query->first();
    }

    protected function formatProductContext(Product $product, array $entities = [], ?string $channelType = null): string
    {
        // Marketplace channels (Trendyol, HB) should NOT see CRM prices or website URLs
        // Prices differ per platform, and cross-channel links are forbidden
        $isMarketplace = in_array($channelType, ['trendyol-product', 'hepsiburada-product']);

        // Eager-load all active variants (including out-of-stock) and review summary
        $product->load([
            'variants' => fn ($q) => $q->withoutGlobalScopes()
                ->where('is_active', true)
                ->orderBy('sku'),
            'reviewSummary' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $lines = ['[Ürün Bilgisi]'];
        $lines[] = "Ürün: {$product->name}";
        $lines[] = "SKU: {$product->sku}";

        // Marketplace channels: no URL, no price (prices differ per platform)
        if (! $isMarketplace && $product->url) {
            $lines[] = "URL: {$product->url}";
        }

        $variants = $product->variants;

        if (! $isMarketplace) {
            // Price — show variant price range if prices differ
            $variantPrices = $variants->map(fn ($v) => (float) ($v->discounted_price ?: $v->price))->filter()->unique();
            if ($variantPrices->count() > 1) {
                $lines[] = 'Fiyat Aralığı: ' . number_format($variantPrices->min(), 2, ',', '.') . ' - ' . number_format($variantPrices->max(), 2, ',', '.') . ' TL';
            } elseif ($product->discounted_price && $product->discounted_price < $product->price) {
                $lines[] = 'Fiyat: ' . number_format($product->price, 2, ',', '.') . ' TL (İndirimli: ' . number_format($product->discounted_price, 2, ',', '.') . ' TL)';
            } elseif ($product->price) {
                $lines[] = 'Fiyat: ' . number_format($product->price, 2, ',', '.') . ' TL';
            }
        }

        if ($variants->isNotEmpty()) {
            $requestedSize = $entities['size'] ?? null;
            $requestedColor = $entities['color'] ?? null;

            $sizeList = $variants->map(function ($v) use ($requestedSize) {
                $attrs = $v->attributes ?? [];
                $label = $attrs['size'] ?? $attrs['Beden'] ?? $attrs['beden'] ?? $v->sku;
                $stock = (int) $v->stock_quantity;

                if ($stock <= 0) {
                    return "{$label}(TÜKENDİ)";
                }

                $marker = ($requestedSize && strtoupper((string) $label) === strtoupper((string) $requestedSize)) ? ' ← STOKTA' : '';

                return "{$label}(mevcut){$marker}";
            })->implode(', ');
            $lines[] = "Mevcut Bedenler: {$sizeList}";

            // If color requested, show matching color variants
            if ($requestedColor) {
                $colorMatches = $variants->filter(function ($v) use ($requestedColor) {
                    $attrs = $v->attributes ?? [];
                    $variantColor = mb_strtolower($attrs['color'] ?? $attrs['Renk'] ?? $attrs['renk'] ?? '');

                    return mb_strpos($variantColor, $requestedColor) !== false;
                });
                if ($colorMatches->isNotEmpty()) {
                    $lines[] = "İstenen Renk ({$requestedColor}): stokta mevcut";
                } else {
                    $lines[] = "İstenen Renk ({$requestedColor}): BU RENKTE STOK YOK";
                }
            }
        }

        // ── Sibling products (same model, different colors) ──
        $skuParts = explode('|', $product->sku);
        if (count($skuParts) >= 2) {
            $modelBase = $skuParts[0] . '|' . $skuParts[1] . '|';
            $siblings = Product::withoutGlobalScopes()
                ->where('id', '<>', $product->id)
                ->where('sku', 'like', $modelBase . '%')
                ->where('is_active', true)
                ->when($product->tenant_id, fn ($q) => $q->where('tenant_id', $product->tenant_id))
                ->with(['variants' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true)->where('stock_quantity', '>', 0)])
                ->get();

            $siblings = $siblings->filter(fn ($s) => $s->variants->isNotEmpty())
                ->sortByDesc(fn ($s) => $s->variants->sum('stock_quantity'))
                ->take(5);

            if ($siblings->isNotEmpty()) {
                $sibLines = [];
                foreach ($siblings as $sib) {
                    $color = $sib->variants->first()?->attributes['Renk'] ?? $sib->variants->first()?->attributes['renk'] ?? '?';
                    $sibSizes = $sib->variants->map(function ($v) {
                        return $v->attributes['Beden'] ?? $v->attributes['beden'] ?? $v->attributes['size'] ?? '?';
                    })->implode(', ');
                    $sibLines[] = "  {$color}: beden {$sibSizes} mevcut" . (! $isMarketplace && $sib->url ? " | {$sib->url}" : '');
                }
                $lines[] = "\nAynı Modelin Diğer Renkleri (stokta):";
                foreach ($sibLines as $sl) {
                    $lines[] = $sl;
                }
            }
        }

        // Category
        if ($product->category_path) {
            $lines[] = "Kategori: {$product->category_path}";
        } elseif ($product->category) {
            $lines[] = "Kategori: {$product->category}";
        }

        // Review summary
        $summary = $product->reviewSummary;
        if ($summary && $summary->rate_count > 0) {
            $lines[] = "Puan: {$summary->avg_rate}/5 ({$summary->rate_count} değerlendirme)";
            if ($summary->ai_summary) {
                $lines[] = "Yorum Özeti: {$summary->ai_summary}";
            }
        }

        return implode("\n", $lines);
    }

    protected function resolveOrderContext(string $prompt, ?Conversation $conversation, ?int $tenantId, array $entities = []): ?string
    {
        $customer = $conversation?->customer;

        // Try to extract order number from entities
        $orderNo = $entities['order_no'] ?? null;

        $lines = [];
        $orders = collect();

        // Sipariş numarası varsa ara. GÜVENLİK: konuşmanın bağlı müşterisi varsa sipariş O
        // müşteriye ait olmalı — yoksa müşteri, başkasının sipariş no'sunu vererek onun sipariş
        // ve profil bilgisini (ad/şehir/harcama) AI önerisine sızdırabilir (cross-customer PII).
        if ($orderNo) {
            $order = Order::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('order_no', $orderNo)
                ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
                ->with(['items' => fn ($q) => $q->withoutGlobalScopes(), 'customer'])
                ->first();

            if ($order) {
                $orders->push($order);
                // Profil YALNIZCA konuşmanın kendi müşterisi için kurulur; rastgele sipariş no ile
                // başka müşterinin profili yüklenmez.
                if ($customer) {
                    $lines = $this->buildCustomerProfile($customer, $tenantId);
                }
            }
        }

        // If no order found by number and we have a customer, try recent orders
        if ($orders->isEmpty() && $customer) {
            if (empty($lines)) {
                $lines = $this->buildCustomerProfile($customer, $tenantId);
            }

            $orders = Order::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('customer_id', $customer->id)
                ->with(['items' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderByDesc('order_date')
                ->limit(3)
                ->get();
        }

        if ($orders->isEmpty()) {
            return null;
        }

        foreach ($orders as $order) {
            $date = $order->order_date ? $order->order_date->format('d.m.Y') : '';
            $header = "[Sipariş #{$order->order_no} — {$date} — {$order->status_label}]";
            $lines[] = '';
            $lines[] = $header;
            $lines[] = 'Toplam: ' . number_format($order->total_amount, 2, ',', '.') . ' TL';

            // Only show cargo/tracking info for shipped+ statuses (6=Kargoda, 7=Teslim, 18=Teslim Edilemedi, 19-21=Mağaza)
            $shippedStatuses = [6, 7, 18, 19, 20, 21];
            if ($order->cargo_company && in_array((int) $order->status, $shippedStatuses)) {
                $cargoLine = "Kargo: {$order->cargo_company}";
                if ($order->cargo_tracking_no) {
                    $cargoLine .= " | Takip No: {$order->cargo_tracking_no}";
                }
                $lines[] = $cargoLine;

                // Tracking URL — generate from known carriers (DB URLs may be incorrect)
                $trackingUrl = $this->buildCargoTrackingUrl($order->cargo_company, $order->cargo_tracking_no, $order->order_no);
                if (! $trackingUrl) {
                    $trackingUrl = $order->cargo_tracking_url;
                }
                if ($trackingUrl) {
                    $lines[] = "Takip Linki: {$trackingUrl}";
                }
            }

            // Order items
            foreach ($order->items as $item) {
                $itemLine = "- {$item->product_name}";
                if ($item->variant_info) {
                    $itemLine .= " ({$item->variant_info})";
                }
                $itemLine .= " x{$item->quantity} — " . number_format($item->total_price, 2, ',', '.') . ' TL';
                $lines[] = $itemLine;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build tracking URL for known cargo carriers.
     * Sürat Kargo uses order_no (TB prefix), others use tracking_no.
     */
    protected function buildCargoTrackingUrl(string $company, ?string $trackingNo, ?string $orderNo = null): ?string
    {
        return CargoTracking::url($company, $trackingNo, $orderNo);
    }

    /**
     * Build enriched customer profile with segment
     */
    protected function buildCustomerProfile(Customer $customer, ?int $tenantId): array
    {
        // Use pre-aggregated fields from customers table (updated by sync)
        $totalOrders = (int) ($customer->total_orders ?? 0);
        $totalSpent = (float) ($customer->total_spent ?? 0);

        // Segment rules
        if ($totalOrders >= 20) {
            $segment = 'VIP Müşteri';
        } elseif ($totalOrders >= 10) {
            $segment = 'Sadık Müşteri';
        } elseif ($totalOrders >= 3) {
            $segment = 'Düzenli Müşteri';
        } else {
            $segment = 'Yeni Müşteri';
        }

        $city = $customer->city ?? $customer->metadata['city'] ?? null;

        $lines = ['[Müşteri Profili]'];
        $lines[] = 'Ad Soyad: ' . $customer->name . ($customer->surname ? " {$customer->surname}" : '');
        if ($city) {
            $lines[] = "Şehir: {$city}";
        }
        $lines[] = "Toplam Sipariş: {$totalOrders} adet";
        $lines[] = 'Toplam Harcama: ' . number_format($totalSpent, 2, ',', '.') . ' TL';
        $lines[] = "Segment: {$segment}";

        return $lines;
    }

}
