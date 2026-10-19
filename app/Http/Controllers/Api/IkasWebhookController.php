<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ikas webhook alıcısı. Payload: { scope, merchantId, data(JSON STRING), signature }.
 * İmza = hash_hmac('sha256', data-string'in KENDİSİ, client_secret) — data'yı
 * yeniden encode etmeden doğrula. Kanal, merchantId → settings.ikas_merchant_id
 * eşleşmesiyle bulunur (çok-tenant güvenli).
 *
 * Not: ikas 200 almazsa 3 kez dener sonra keser; sürekli hata webhook'u
 * bloklatır → her zaman hızlı 200 dön, işleme hatasını logla.
 */
class IkasWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $scope = (string) $request->input('scope', '');
        $merchantId = (string) $request->input('merchantId', '');
        $data = $request->input('data');
        $signature = (string) $request->input('signature', '');

        if ($scope === '' || ! is_string($data)) {
            return response()->json(['ok' => true]);
        }

        $channel = $this->resolveChannel($merchantId);
        if (! $channel) {
            Log::warning('ikas webhook: merchantId eşleşmedi', ['merchant_id' => $merchantId, 'scope' => $scope]);

            return response()->json(['ok' => true]);
        }

        $secret = (string) (($channel->credentials ?? [])['client_secret'] ?? '');
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $data, $secret), $signature)) {
            Log::warning('ikas webhook: imza doğrulanamadı', ['channel_id' => $channel->id, 'scope' => $scope]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $entity = json_decode($data, true);
        if (! is_array($entity)) {
            return response()->json(['ok' => true]);
        }

        try {
            match (true) {
                str_starts_with($scope, 'store/customerFavoriteProducts/') => $this->handleFavorite($channel, $entity),
                str_starts_with($scope, 'store/stock/') => $this->handleStock($channel, $entity),
                default => Log::info('ikas webhook: işlenmeyen scope', ['scope' => $scope]),
            };
        } catch (\Throwable $e) {
            Log::error('ikas webhook işleme hatası', [
                'scope' => $scope,
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
                'data' => mb_substr($data, 0, 500),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    protected function resolveChannel(string $merchantId): ?Channel
    {
        if ($merchantId === '') {
            return null;
        }

        return Channel::withoutGlobalScopes()
            ->where('type', 'ikas-ecommerce')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn ($ch) => (($ch->settings ?? [])['ikas_merchant_id'] ?? null) === $merchantId);
    }

    /**
     * customerFavoriteProducts created/updated — beklenen alanlar:
     * id, customerId, productIds[] (tekil productId varyantına da tolerans).
     * İlk gerçek olaylarda şema sürprizine karşı ham veri loglanır.
     */
    protected function handleFavorite(Channel $channel, array $e): void
    {
        Log::info('ikas favori olayı', ['channel_id' => $channel->id, 'keys' => array_keys($e)]);

        $customerPlatformId = (string) ($e['customerId'] ?? $e['customer_id'] ?? '');
        $productIds = $e['productIds'] ?? $e['productIdList'] ?? (isset($e['productId']) ? [$e['productId']] : []);

        if ($customerPlatformId === '' || ! is_array($productIds) || $productIds === []) {
            Log::warning('ikas favori: beklenmedik şema', ['data' => $e]);

            return;
        }

        $customerId = Customer::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('platform_customer_id', $customerPlatformId)
            ->value('id');

        foreach ($productIds as $pid) {
            $pid = (string) $pid;
            $product = Product::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('platform_product_id', $pid)
                ->first(['id', 'name', 'sku', 'images', 'price', 'currency', 'stock_quantity']);

            ProductFavorite::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $channel->tenant_id,
                    // ikas'ta ayrı favori-ID yok; müşteri+ürün ikilisi kimliktir
                    'platform_favorite_id' => "ikas:{$customerPlatformId}:{$pid}",
                ],
                [
                    'channel_id' => $channel->id,
                    'customer_id' => $customerId,
                    'product_id' => $product?->id,
                    'platform_customer_id' => $customerPlatformId,
                    'platform_product_id' => $pid,
                    'product_name' => $product->name ?? 'Ürün',
                    'product_sku' => $product->sku ?? null,
                    'product_image' => $product?->images[0] ?? null,
                    'product_price' => (float) ($product->price ?? 0),
                    'product_price_tax' => (float) ($product->price ?? 0),
                    'currency' => $product->currency ?? 'TRY',
                    'stock_quantity' => (float) ($product->stock_quantity ?? 0),
                    'favorited_at' => now(),
                ]
            );
        }
    }

    /**
     * store/stock/updated — ürünün yerel stok kopyasını tazele; "gelince
     * haber ver" bildirimleri stok senkronu/notifier tarafında tetiklenir.
     * Beklenen alanlar: productId, variantId, stockCount (şema sürprizi loglanır).
     */
    protected function handleStock(Channel $channel, array $e): void
    {
        $pid = (string) ($e['productId'] ?? '');
        $stock = $e['stockCount'] ?? null;

        if ($pid === '' || ! is_numeric($stock)) {
            Log::info('ikas stok olayı: beklenmedik şema', ['keys' => array_keys($e)]);

            return;
        }

        // Varyant stoğu geldiğinde ürün toplamını yeniden kurmak yerine
        // notifier'ın tazeliği için ürünün stok alanını iyimser güncelle
        // (gece ürün sync'i kesin değeri yazar).
        Product::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->where('platform_product_id', $pid)
            ->where('stock_quantity', '<', (float) $stock)
            ->update(['stock_quantity' => (float) $stock, 'updated_at' => now()]);
    }
}
