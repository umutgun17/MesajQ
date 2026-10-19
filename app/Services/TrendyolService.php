<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrendyolService
{
    protected string $baseUrl;
    protected string $sellerId;
    protected string $apiKey;
    protected string $apiSecret;

    /**
     * Shared cookie jar path for Cloudflare session persistence (review API).
     * CURLOPT_COOKIEJAR/COOKIEFILE handles __cf_bm cookie automatically.
     */
    protected static string $cookieJarPath = '';

    public function __construct()
    {
        $this->baseUrl = config('trendyol.base_url', 'https://apigw.trendyol.com');
        $this->sellerId = config('trendyol.seller_id', '');
        $this->apiKey = config('trendyol.api_key', '');
        $this->apiSecret = config('trendyol.api_secret', '');
    }

    public static function fromChannel(Channel $channel): self
    {
        $instance = new self();
        $creds = $channel->credentials ?? [];
        $instance->sellerId  = $creds['seller_id'] ?? '';
        $instance->apiKey    = $creds['api_key'] ?? '';
        $instance->apiSecret = $creds['api_secret'] ?? '';

        return $instance;
    }

    /**
     * Urun/katalog ucu icin kimlik: kanalda ayri bir "Urun API" anahtari (product_api_key/secret)
     * tanimliysa onu, yoksa ana anahtari kullanir. Soru-Cevap/yorum akislari fromChannel() ile
     * ana anahtarda kalir; boylece ikinci anahtar girmek mevcut akislari bozmaz.
     */
    public static function fromChannelForProducts(Channel $channel): self
    {
        $instance = self::fromChannel($channel);
        $creds = $channel->credentials ?? [];
        if (! empty($creds['product_api_key']) && ! empty($creds['product_api_secret'])) {
            $instance->apiKey    = $creds['product_api_key'];
            $instance->apiSecret = $creds['product_api_secret'];
            $instance->usesProductKey = true;
        }

        return $instance;
    }

    /** fromChannelForProducts ikinci anahtari sectiyse true. */
    public bool $usesProductKey = false;

    public function isAvailable(): bool
    {
        return ! empty($this->sellerId) && ! empty($this->apiKey) && ! empty($this->apiSecret);
    }

    /**
     * Get filtered questions from Trendyol Q&A API
     */
    public function getQuestions(array $params = []): array
    {
        $defaults = [
            'orderByField' => 'CreatedDate',
            'orderByDirection' => 'DESC',
            'page' => 0,
            'size' => 50,
        ];

        $query = array_merge($defaults, $params);

        // Trendyol API silently returns empty for size > 200
        if ($query['size'] > 200) {
            $query['size'] = 200;
        }

        $response = $this->request('GET', "/integration/qna/sellers/{$this->sellerId}/questions/filter", $query);

        if (! $response) {
            return ['content' => [], 'totalElements' => 0, 'totalPages' => 0, 'page' => 0];
        }

        return $response;
    }

    /**
     * Get a single question by ID
     */
    public function getQuestion(int $questionId): ?array
    {
        return $this->request('GET', "/integration/qna/sellers/{$this->sellerId}/questions/{$questionId}");
    }

    /**
     * Answer a customer question
     */
    public function answerQuestion(int $questionId, string $text): array
    {
        $response = $this->request('POST', "/integration/qna/sellers/{$this->sellerId}/questions/{$questionId}/answers", [], [
            'text' => $text,
        ]);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError ?? 'Trendyol cevabı kabul etmedi.'];
        }

        return ['success' => true, 'data' => $response];
    }

    /**
     * Get seller products from Trendyol Seller API (paginated).
     * Yeni entegrasyon yolu: /integration/product/sellers/{id}/products
     * (eski /sapigw/suppliers/... 556 donuyor). Urun yetkisi olmayan anahtar 401 verir.
     */
    public function getSellerProducts(array $params = []): array
    {
        $defaults = [
            'page' => 0,
            'size' => 200,
        ];

        $query = array_merge($defaults, $params);

        // Trendyol API silently returns empty for size > 200
        if ($query['size'] > 200) {
            $query['size'] = 200;
        }

        $response = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products", $query);

        if (! $response) {
            return ['content' => [], 'totalElements' => 0, 'totalPages' => 0, 'page' => 0, 'error' => $this->lastError];
        }

        return $response;
    }

    /**
     * Urun API'sine erisim var mi? (401 = anahtarda urun yetkisi yok)
     *
     * @return array{ok:bool,status:int|null,message:string|null,total:int|null}
     */
    public function probeProductAccess(): array
    {
        $res = $this->getSellerProducts(['page' => 0, 'size' => 1]);
        if (isset($res['error'])) {
            $status = null;
            if (preg_match('/HTTP (\d{3})/', (string) $res['error'], $m)) {
                $status = (int) $m[1];
            }

            return ['ok' => false, 'status' => $status, 'message' => $res['error'], 'total' => null];
        }

        return ['ok' => true, 'status' => 200, 'message' => null, 'total' => (int) ($res['totalElements'] ?? 0)];
    }

    /**
     * Tum katalogu sayfa sayfa gezer; her sayfa icin $onPage(content[], page, totalPages) cagirir.
     * Donus: toplam islenen satir; hata olursa null (kismi sonuc onPage ile zaten teslim edildi).
     */
    public function eachSellerProduct(callable $onPage, array $params = [], int $maxPages = 500): ?int
    {
        $page = 0;
        $total = 0;
        do {
            $res = $this->getSellerProducts(array_merge($params, ['page' => $page, 'size' => 200]));
            if (isset($res['error'])) {
                return null;
            }
            $content = $res['content'] ?? [];
            $totalPages = (int) ($res['totalPages'] ?? 0);
            $onPage($content, $page, $totalPages);
            $total += count($content);
            $page++;
            if ($page < $totalPages) {
                usleep(250000); // nazik tempo (rate limit)
            }
        } while ($page < $totalPages && $page < $maxPages && ! empty($content));

        return $total;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get product reviews from Trendyol public API (no auth required)
     * Uses cookie jar for Cloudflare session persistence.
     * Returns ['retry_after' => N] on 429 so caller can handle wait.
     *
     * @return array|null JSON data on success, ['retry_after' => int] on 429, null on other errors
     */
    public function getProductReviews(int $contentId, int $page = 0): array|null
    {
        $publicBaseUrl = config('trendyol.public_base_url', 'https://apigw.trendyol.com');
        $endpointTemplate = config('trendyol.review_endpoint', '/discovery-web-socialgw-service/api/review/{contentId}');
        $path = str_replace('{contentId}', (string) $contentId, $endpointTemplate);

        // Ensure cookie jar file exists (curl persists __cf_bm across requests)
        if (empty(self::$cookieJarPath)) {
            self::$cookieJarPath = storage_path('app/trendyol_cf_cookies.txt');
            if (! file_exists(self::$cookieJarPath)) {
                file_put_contents(self::$cookieJarPath, '');
            }
        }

        try {
            $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Referer' => 'https://www.trendyol.com/',
                    'Origin' => 'https://www.trendyol.com',
                    'sec-ch-ua' => '"Chromium";v="131", "Not_A Brand";v="24"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'empty',
                    'Sec-Fetch-Mode' => 'cors',
                    'Sec-Fetch-Site' => 'same-site',
                ])
                ->withOptions([
                    'curl' => [
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_COOKIEJAR => self::$cookieJarPath,
                        CURLOPT_COOKIEFILE => self::$cookieJarPath,
                    ],
                ])
                ->timeout(15)
                ->get("{$publicBaseUrl}{$path}", [
                    'page' => $page,
                    'culture' => 'tr-TR',
                ]);

            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 600);
                Log::info("TrendyolService: 429 rate limited, Retry-After: {$retryAfter}s", [
                    'contentId' => $contentId,
                    'page' => $page,
                ]);

                return ['retry_after' => $retryAfter];
            }

            if ($response->failed()) {
                Log::warning("TrendyolService: Public review API failed", [
                    'contentId' => $contentId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('TrendyolService: Review API exception', [
                'contentId' => $contentId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }


    /**
     * Make an authenticated request to Trendyol API
     */
    /** Son başarısız isteğin personele gösterilecek açıklaması (14 Eyl 2026). */
    protected ?string $lastError = null;

    protected function request(string $method, string $path, array $query = [], array $body = []): ?array
    {
        try {
            $http = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->withHeaders([
                    'User-Agent' => "{$this->sellerId} - SelfIntegration",
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
                $body = $response->json();
                Log::error("TrendyolService: {$method} {$path} failed", [
                    'status' => $response->status(),
                    'body' => $body,
                ]);
                $detail = $body['errors'][0]['message'] ?? ($body['message'] ?? null);
                $this->lastError = 'Trendyol cevabı kabul etmedi (HTTP ' . $response->status() . ')'
                    . (is_string($detail) ? ': ' . $detail : '') . '.';

                return null;
            }

            $this->lastError = null;

            return $response->json() ?? [];
        } catch (\Exception $e) {
            Log::error('TrendyolService: Exception', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);
            $this->lastError = 'Trendyol API\'ye ulaşılamadı: ' . $e->getMessage();

            return null;
        }
    }
}
