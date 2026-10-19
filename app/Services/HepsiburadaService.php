<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HepsiburadaService
{
    protected string $baseUrl;
    protected string $merchantId;
    protected string $apiKey;
    protected string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = config('hepsiburada.base_url', 'https://api-asktoseller-merchant.hepsiburada.com');
        $this->merchantId = config('hepsiburada.merchant_id', '');
        $this->apiKey = config('hepsiburada.api_key', '');
        $this->apiSecret = config('hepsiburada.api_secret', '');
    }

    public static function fromChannel(Channel $channel): self
    {
        $instance = new self();
        $creds = $channel->credentials ?? [];
        $instance->merchantId = $creds['merchant_id'] ?? '';
        $instance->apiKey     = $creds['api_key'] ?? '';
        $instance->apiSecret  = $creds['api_secret'] ?? '';
        $instance->baseUrl    = $creds['base_url'] ?? 'https://api-asktoseller-merchant.hepsiburada.com';

        return $instance;
    }

    public function isAvailable(): bool
    {
        return ! empty($this->merchantId) && ! empty($this->apiKey) && ! empty($this->apiSecret);
    }

    /**
     * Get issues (questions) from Hepsiburada Ask-to-Seller API
     *
     * @param array $params Query parameters (status, page, size)
     */
    public function getQuestions(array $params = []): array
    {
        $defaults = [
            'page' => 1,
            'size' => 50,
        ];

        $query = array_merge($defaults, $params);

        $response = $this->request('GET', '/api/v1.0/issues', $query);

        if (! $response) {
            return ['data' => [], 'totalCount' => 0, 'totalPages' => 0, 'page' => 1];
        }

        return $response;
    }

    /**
     * Get a single issue (question) by number
     */
    public function getQuestion(int|string $number): ?array
    {
        return $this->request('GET', "/api/v1.0/issues/{$number}");
    }

    /**
     * Answer a customer question (multipart/form-data with 'Answer' field)
     */
    /**
     * Cevap gönderimi. Başarısızlıkta Hepsiburada'nın kendi hata metnini de
     * döndürür ('error') — panelde personele gösterilir. Hepsiburada'nın
     * otomatik içerik filtresi (code 1001: "uygunsuz ifade", "iletişim bilgisi
     * paylaşılamaz") cevabı sessizce reddediyordu; 14 Eyl 2026.
     */
    public function answerQuestion(int|string $number, string $text): array
    {
        try {
            $response = Http::withBasicAuth($this->merchantId, $this->apiSecret)
                ->withHeaders([
                    'merchantId' => $this->merchantId,
                    'User-Agent' => $this->apiKey,
                ])
                ->timeout(30)
                ->asMultipart()
                ->post("{$this->baseUrl}/api/v1.0/issues/{$number}/answer", [
                    ['name' => 'Answer', 'contents' => $text],
                ]);

            if ($response->failed()) {
                $body = $response->json();
                Log::error("HepsiburadaService: POST /api/v1.0/issues/{$number}/answer failed", [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return ['success' => false, 'error' => $this->describeError($body, $response->status())];
            }

            return ['success' => true, 'data' => $response->body()];
        } catch (\Exception $e) {
            Log::error('HepsiburadaService: answerQuestion exception', [
                'issue' => $number,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Hepsiburada API\'ye ulaşılamadı: ' . $e->getMessage()];
        }
    }

    /**
     * Hepsiburada hata gövdesinden personele gösterilecek kısa, Türkçe metin.
     * Bilinen kodlar: 1001 = içerik filtresi (küfür yanlış pozitifi veya
     * link/telefon/site adresi), 5000 = Hepsiburada tarafında teknik hata.
     */
    protected function describeError(?array $body, int $status): string
    {
        $code = $body['code'] ?? null;
        $msg  = $body['errors'][0]['message'] ?? ($body['message'] ?? null);
        $hint = $body['errors'][0]['internalMessage'] ?? null;

        if ($code === 1001 && is_string($msg)) {
            if (str_contains($msg, 'uygunsuz')) {
                $flagged = null;
                // internalMessage örn. "null|kontrol@tavsiye.ederiz" → filtreye takılan kalıp
                if (is_string($hint) && str_contains($hint, '|')) {
                    $flagged = str_replace(['@', '.'], ' … ', trim(substr($hint, strpos($hint, '|') + 1)));
                    $flagged = trim(preg_replace('/\s+/', ' ', $flagged));
                }

                return 'Hepsiburada içerik filtresi cevabı reddetti ("uygunsuz ifade" — yanlış pozitif olabilir'
                    . ($flagged ? ', takılan kalıp: "' . $flagged . '"' : '')
                    . '). "… etmenizi tavsiye/rica ederiz" yerine "… etmenizi öneririz" gibi yazıp tekrar gönderin.';
            }
            if (str_contains($msg, 'iletişim bilgileri')) {
                return 'Hepsiburada cevabı reddetti: cevapta link, telefon, e-posta, site adresi veya başka platforma yönlendirme olamaz. Bunları çıkarıp tekrar gönderin.';
            }

            return 'Hepsiburada cevabı reddetti: ' . $msg;
        }

        if ($code === 5000) {
            return 'Hepsiburada tarafında geçici teknik hata — birkaç dakika sonra tekrar gönderin.';
        }

        return 'Hepsiburada cevabı kabul etmedi (HTTP ' . $status . ')' . (is_string($msg) ? ': ' . $msg : '') . '.';
    }

    /**
     * Make an authenticated request to Hepsiburada API
     */
    protected function request(string $method, string $path, array $query = [], array $body = []): ?array
    {
        try {
            $http = Http::withBasicAuth($this->merchantId, $this->apiSecret)
                ->withHeaders([
                    'merchantId' => $this->merchantId,
                    'User-Agent' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->timeout(30);

            if ($method === 'GET') {
                $response = $http->get("{$this->baseUrl}{$path}", $query);
            } else {
                $url = "{$this->baseUrl}{$path}";
                if (! empty($query)) {
                    $url .= '?' . http_build_query($query);
                }
                $response = $http->post($url, $body);
            }

            if ($response->failed()) {
                Log::error("HepsiburadaService: {$method} {$path} failed", [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $response->json() ?? [];
        } catch (\Exception $e) {
            Log::error('HepsiburadaService: Exception', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
