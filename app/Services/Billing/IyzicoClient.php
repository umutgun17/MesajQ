<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * iyzico REST istemcisi (IYZWSv2 HMAC-SHA256). SDK bağımlılığı yok.
 *
 * İlk ödeme: Ödeme Formu (checkout form) + kart saklama (registerCard=1, 3D Secure iyzico tarafında).
 * Yenileme: saklı kart (cardUserKey + cardToken) ile /payment/auth (3DS'siz tahsilat; iyzico
 * hesabında "saklı kartla ödeme" yetkisi açık olmalıdır).
 */
class IyzicoClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        $cfg = config('saas.iyzico');

        return new self((string) $cfg['api_key'], (string) $cfg['secret_key'], rtrim((string) $cfg['base_url'], '/'));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }

    /** @return array{Authorization:string,'x-iyzi-rnd':string} */
    public function authHeaders(string $uriPath, string $body, ?string $randomKey = null): array
    {
        $randomKey = $randomKey ?: ((string) (now()->timestamp * 1000) . Str::random(8));
        $signature = hash_hmac('sha256', $randomKey . $uriPath . $body, $this->secretKey);

        return [
            'Authorization' => 'IYZWSv2 ' . base64_encode("apiKey:{$this->apiKey}&randomKey:{$randomKey}&signature:{$signature}"),
            'x-iyzi-rnd' => $randomKey,
        ];
    }

    public function post(string $path, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('iyzico kimlikleri tanımlı değil (IYZICO_API_KEY / IYZICO_SECRET_KEY).');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $resp = Http::withHeaders($this->authHeaders($path, $body) + ['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->timeout(30)
            ->withBody($body, 'application/json')
            ->post($this->baseUrl . $path);

        $json = $resp->json() ?? [];
        if (! $resp->ok() || ($json['status'] ?? 'failure') !== 'success') {
            Log::warning('iyzico.request_failed', ['path' => $path, 'http' => $resp->status(), 'errorCode' => $json['errorCode'] ?? null, 'errorMessage' => $json['errorMessage'] ?? null]);
        }

        return $json;
    }

    /** Ödeme formu başlat → paymentPageUrl / checkoutFormContent / token döner. */
    public function initializeCheckoutForm(array $payload): array
    {
        return $this->post('/payment/iyzipos/checkoutform/initialize/auth/ecom', $payload);
    }

    /** Ödeme formu sonucunu token ile getir. */
    public function retrieveCheckoutForm(string $token, string $conversationId): array
    {
        return $this->post('/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'tr', 'conversationId' => $conversationId, 'token' => $token,
        ]);
    }

    /** Saklı kartla tahsilat (3DS'siz). */
    public function chargeStoredCard(array $payload): array
    {
        return $this->post('/payment/auth', $payload);
    }

    public function deleteCard(string $cardUserKey, string $cardToken): array
    {
        return $this->post('/cardstorage/card/delete', ['locale' => 'tr', 'cardUserKey' => $cardUserKey, 'cardToken' => $cardToken]);
    }
}
