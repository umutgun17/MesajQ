<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ikas Admin API istemcisi (GraphQL v2 + OAuth2 client_credentials).
 *
 * Kimlikler kanal credentials'ından gelir: store_name (mağazanın
 * {store}.myikas.com alt alan adı), client_id, client_secret
 * (ikas admin → Uygulamalar → Uygulamalarım → Özel Uygulama Oluştur).
 *
 * Notlar:
 *  - Token 4 saat geçerli; 3.5 saat cache'lenir, 401'de düşürülüp yenilenir.
 *  - Rate limit 50 istek/10 sn → her çağrı sonrası ~250ms bekleme + 429'da backoff.
 *  - Tüm tarihler epoch MİLİSANİYE'dir.
 *  - list* sorguları offset sayfalama: pagination{page,limit(max 200)} → {data,count,hasNext}.
 */
class IkasService
{
    public const GRAPHQL_URL = 'https://api.myikas.com/api/v2/admin/graphql';

    public function __construct(
        protected ?Channel $channel = null,
        protected string $storeName = '',
        protected string $clientId = '',
        protected string $clientSecret = '',
    ) {}

    public static function fromChannel(Channel $channel): self
    {
        $creds = $channel->credentials ?? [];

        return new self(
            $channel,
            trim((string) ($creds['store_name'] ?? '')),
            trim((string) ($creds['client_id'] ?? '')),
            trim((string) ($creds['client_secret'] ?? '')),
        );
    }

    public function isAvailable(): bool
    {
        return $this->storeName !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    protected function tokenCacheKey(): string
    {
        return 'ikas:token:' . md5($this->storeName . '|' . $this->clientId);
    }

    protected function token(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addHours(3)->addMinutes(30), function () {
            $resp = Http::asForm()->timeout(30)->post(
                "https://{$this->storeName}.myikas.com/api/admin/oauth/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]
            );

            if (! $resp->successful() || ! $resp->json('access_token')) {
                Log::error('IkasService: token alınamadı', [
                    'store' => $this->storeName,
                    'status' => $resp->status(),
                    'body' => mb_substr($resp->body(), 0, 300),
                ]);
                throw new \RuntimeException("ikas token alınamadı (HTTP {$resp->status()})");
            }

            return (string) $resp->json('access_token');
        });
    }

    /**
     * GraphQL çağrısı. 429'da backoff'lu tekrar, 401'de token yenileme.
     * GraphQL 'errors' dizisi hata sayılır (ikas'ın hata-oranı blok merdivenine
     * takılmamak için kırık sorgu asla döngüde bırakılmamalı).
     */
    public function graphql(string $query, array $variables = []): array
    {
        $lastStatus = 0;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $resp = Http::withToken($this->token())
                ->timeout(60)
                ->post(self::GRAPHQL_URL, [
                    'query' => $query,
                    'variables' => $variables === [] ? (object) [] : $variables,
                ]);

            $lastStatus = $resp->status();

            if ($lastStatus === 429) {
                sleep(3 * $attempt);
                continue;
            }

            if ($lastStatus === 401) {
                Cache::forget($this->tokenCacheKey());
                continue;
            }

            if (! $resp->successful()) {
                throw new \RuntimeException('ikas GraphQL HTTP ' . $lastStatus . ': ' . mb_substr($resp->body(), 0, 300));
            }

            $json = $resp->json();
            if (! empty($json['errors'])) {
                throw new \RuntimeException('ikas GraphQL hatası: ' . mb_substr(json_encode($json['errors'][0] ?? [], JSON_UNESCAPED_UNICODE), 0, 400));
            }

            usleep(250_000); // 50 istek/10sn limitine saygı

            return $json['data'] ?? [];
        }

        throw new \RuntimeException("ikas GraphQL: denemeler tükendi (son HTTP {$lastStatus})");
    }

    /** Merchant id — görsel CDN URL'leri için gerekli; kanal ayarında cache'lenir. */
    public function merchantId(): ?string
    {
        $settings = $this->channel?->settings ?? [];
        if (! empty($settings['ikas_merchant_id'])) {
            return (string) $settings['ikas_merchant_id'];
        }

        try {
            $data = $this->graphql('query { getMerchant { id } }');
            $id = $data['getMerchant']['id'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('IkasService: getMerchant başarısız', ['error' => $e->getMessage()]);
            $id = null;
        }

        if ($id && $this->channel) {
            $settings['ikas_merchant_id'] = $id;
            $this->channel->update(['settings' => $settings]);
        }

        return $id;
    }

    public function imageUrl(string $imageId, int $size = 720): string
    {
        return "https://cdn.myikas.com/images/{$this->merchantId()}/{$imageId}/image_{$size}.webp";
    }

    /** @return array{data: array, hasNext: bool, count: int} */
    /** Tenant ayarındaki storefront_url (sonda / yok); yoksa null. */
    public function storefrontUrl(): ?string
    {
        $tenant = $this->channel?->tenant;
        $url = $tenant?->settings['storefront_url'] ?? null;

        return $url ? rtrim((string) $url, '/') : null;
    }

    public function listProducts(int $page, int $limit = 200): array
    {
        $q = <<<'GQL'
        query($p: PaginationInput) {
          listProduct(pagination: $p, sort: "updatedAt") {
            count hasNext page limit
            data {
              id name description shortDescription totalStock createdAt updatedAt deleted
              googleTaxonomyId
              metaData { slug }
              tags { name }
              brand { id name }
              categories { id name }
              variants {
                id sku barcodeList isActive
                prices { sellPrice discountPrice buyPrice currencyCode priceListId }
                images { imageId isMain order }
                stocks { stockLocationId stockCount }
              }
            }
          }
        }
        GQL;

        $data = $this->graphql($q, ['p' => ['page' => $page, 'limit' => $limit]]);
        $r = $data['listProduct'] ?? [];

        return ['data' => $r['data'] ?? [], 'hasNext' => (bool) ($r['hasNext'] ?? false), 'count' => (int) ($r['count'] ?? 0)];
    }

    /** @return array{data: array, hasNext: bool, count: int} */
    public function listOrders(?int $updatedAtGteMs, int $page, int $limit = 200): array
    {
        $q = <<<'GQL'
        query($p: PaginationInput, $upd: DateFilterInput) {
          listOrder(updatedAt: $upd, pagination: $p, sort: "updatedAt") {
            count hasNext page limit
            data {
              id orderNumber status orderPaymentStatus orderPackageStatus
              totalPrice totalFinalPrice currencyCode couponCode note
              orderedAt createdAt updatedAt cancelledAt
              customerId
              customer { id email firstName lastName fullName phone isGuestCheckout }
              billingAddress { firstName lastName addressLine1 addressLine2 postalCode phone company taxNumber taxOffice city { name } district { name } country { name } }
              shippingAddress { firstName lastName addressLine1 addressLine2 postalCode phone company city { name } district { name } country { name } }
              orderLineItems {
                id quantity price unitPrice finalPrice finalUnitPrice discountPrice taxValue status
                variant { id productId name sku barcodeList mainImageId }
              }
            }
          }
        }
        GQL;

        $vars = ['p' => ['page' => $page, 'limit' => $limit]];
        if ($updatedAtGteMs !== null) {
            $vars['upd'] = ['gte' => $updatedAtGteMs];
        }

        $data = $this->graphql($q, $vars);
        $r = $data['listOrder'] ?? [];

        return ['data' => $r['data'] ?? [], 'hasNext' => (bool) ($r['hasNext'] ?? false), 'count' => (int) ($r['count'] ?? 0)];
    }

    /** @return array{data: array, hasNext: bool, count: int} */
    public function listCustomers(?int $updatedAtGteMs, int $page, int $limit = 200): array
    {
        $q = <<<'GQL'
        query($p: PaginationInput, $upd: DateFilterInput) {
          listCustomer(updatedAt: $upd, pagination: $p, sort: "updatedAt") {
            count hasNext page limit
            data {
              id firstName lastName fullName email phone gender birthDate
              accountStatus subscriptionStatus totalOrderPrice
              firstOrderDate lastOrderDate createdAt updatedAt deleted
            }
          }
        }
        GQL;

        $vars = ['p' => ['page' => $page, 'limit' => $limit]];
        if ($updatedAtGteMs !== null) {
            $vars['upd'] = ['gte' => $updatedAtGteMs];
        }

        $data = $this->graphql($q, $vars);
        $r = $data['listCustomer'] ?? [];

        return ['data' => $r['data'] ?? [], 'hasNext' => (bool) ($r['hasNext'] ?? false), 'count' => (int) ($r['count'] ?? 0)];
    }

    /**
     * Terk edilmiş sepetler (Checkout). input zorunlu ama alanları opsiyonel.
     *
     * @return array{data: array, hasNext: bool, count: int}
     */
    public function listAbandonedCarts(?int $updatedAtGteMs, int $page, int $limit = 200): array
    {
        $q = <<<'GQL'
        query($input: ListAbandonedCartInput!, $p: PaginationInput, $upd: DateFilterInput) {
          listAbandonedCheckouts(input: $input, lastActivityDate: $upd, pagination: $p, sort: "lastActivityDate") {
            count hasNext page limit
            data {
              id cartId status recoveryStatus orderId
              totalFinalPrice createdAt updatedAt
              customer { id email firstName lastName phone }
              cart { items { id quantity finalPrice finalUnitPrice discountPrice taxValue status
                variant { id productId name sku barcodeList } } }
            }
          }
        }
        GQL;

        $vars = ['input' => (object) [], 'p' => ['page' => $page, 'limit' => $limit]];
        if ($updatedAtGteMs !== null) {
            $vars['upd'] = ['gte' => $updatedAtGteMs];
        }

        $data = $this->graphql($q, $vars);
        $r = $data['listAbandonedCheckouts'] ?? [];

        return ['data' => $r['data'] ?? [], 'hasNext' => (bool) ($r['hasNext'] ?? false), 'count' => (int) ($r['count'] ?? 0)];
    }

    /**
     * Tekil sipariş lookup'ı (iade akışı) — orderNumber birebir eşleşme.
     * Alan seti canlıda doğrulandı (2026-07-24, BA5126528): finalPrice = SATIR toplamı
     * (adet dahil), fiyatlar KDV DAHİL, Σsatır = totalFinalPrice.
     *
     * @return array{success:bool, order?:array, error?:string}
     */
    public function getOrderByNo(string $orderNo): array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return ['success' => false, 'error' => 'empty_order_no'];
        }

        $q = <<<'GQL'
        query($no: StringFilterInput) {
          listOrder(orderNumber: $no, pagination: {page: 1, limit: 5}) {
            data {
              id orderNumber status orderPaymentStatus orderPackageStatus
              totalPrice totalFinalPrice netTotalFinalPrice currencyCode couponCode
              orderedAt createdAt cancelledAt
              customer { id email firstName lastName fullName phone }
              billingAddress { firstName lastName addressLine1 addressLine2 postalCode phone city { name } district { name } }
              shippingAddress { firstName lastName addressLine1 addressLine2 postalCode phone city { name } district { name } }
              shippingLines { title price finalPrice isRefunded }
              orderLineItems {
                id quantity price unitPrice finalPrice finalUnitPrice discountPrice taxValue status
                variant { id productId name sku barcodeList mainImageId variantValues { variantTypeName variantValueName } }
              }
            }
          }
        }
        GQL;

        try {
            $data = $this->graphql($q, ['no' => ['eq' => $orderNo]]);
        } catch (\Throwable $e) {
            Log::warning('IkasService: getOrderByNo başarısız', ['order_no' => $orderNo, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'lookup_failed'];
        }

        foreach (($data['listOrder']['data'] ?? []) as $o) {
            if ((string) ($o['orderNumber'] ?? '') === $orderNo) {
                return ['success' => true, 'order' => $o];
            }
        }

        return ['success' => false, 'error' => 'not_found'];
    }

    /** Kategoriler — sayfalamasız tam liste döner. */
    public function listCategories(): array
    {
        $data = $this->graphql('query { listCategory { id name parentId } }');

        return $data['listCategory'] ?? [];
    }

    /** Epoch ms → 'Y-m-d H:i:s' (UTC) ya da null. */
    public static function msToDate(mixed $ms): ?string
    {
        if (! is_numeric($ms) || (int) $ms <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', intdiv((int) $ms, 1000));
    }
}
