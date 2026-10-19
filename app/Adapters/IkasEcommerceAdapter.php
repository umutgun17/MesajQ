<?php

namespace App\Adapters;

use App\Contracts\EcommercePlatformInterface;
use App\Services\IkasService;

/**
 * ikas → EcommercePlatformInterface köprüsü. crm:sync-products bu adapter
 * üzerinden ikas ürünlerini Ticimax ile AYNI normalize sözleşmeyle alır
 * (TicimaxEcommerceAdapter::mapProduct çıktı şekli esas alınmıştır).
 *
 * Notlar:
 *  - listProduct'ta updatedAt filtresi yok → gelen Ticimax-adlı artımlı
 *    filtreler yok sayılır, her çalışmada tam katalog upsert edilir
 *    (ikas mağazaları için kabul edilebilir; sayfa=200).
 *  - Fiyat: varyantın priceListId'siz (varsayılan liste) fiyatı tercih edilir.
 *  - Görsel URL'leri merchantId ile CDN'den kurulur.
 */
class IkasEcommerceAdapter implements EcommercePlatformInterface
{
    protected ?array $categoryLookup = null;

    public function __construct(protected IkasService $service) {}

    public function getService(): IkasService
    {
        return $this->service;
    }

    public function getProducts(array $filters, int $page, int $pageSize): array
    {
        // Kimlik yoksa sessiz-boş dön: ortak crm:sync-products döngüsü diğer
        // kanalları işlemeye devam edebilsin.
        if (! $this->service->isAvailable()) {
            \Illuminate\Support\Facades\Log::warning('IkasEcommerceAdapter: kanal kimlikleri eksik, ürün sync atlandı');

            return ['products' => [], 'total' => 0];
        }

        $r = $this->service->listProducts($page, min($pageSize, 200));

        $products = array_map(fn ($item) => $this->mapProduct($item), $r['data']);

        return ['products' => $products, 'total' => $r['count']];
    }

    public function getProductCount(array $filters): int
    {
        if (! $this->service->isAvailable()) {
            return 0;
        }

        // count toplam kayıt sayısıdır; tek satırlık sayfa ile alınır.
        $r = $this->service->listProducts(1, 1);

        return $r['count'];
    }

    public function getCategories(): array
    {
        return $this->service->listCategories();
    }

    public function getBrands(): array
    {
        return []; // ürün üstünde brand.name geliyor; ayrı liste v1'de gereksiz
    }

    protected function mapProduct(array $item): array
    {
        $variants = array_map(fn ($v) => $this->mapVariant($v), $item['variants'] ?? []);
        $first = ($item['variants'] ?? [])[0] ?? [];
        $firstPrice = $this->pickPrice($first['prices'] ?? []);

        // Görseller: ana görsel öne, sonra sıraya göre; tüm varyantlardan tekilleştir
        $imageIds = [];
        foreach ($item['variants'] ?? [] as $v) {
            foreach ($v['images'] ?? [] as $img) {
                if (! empty($img['imageId'])) {
                    $imageIds[$img['imageId']] = [(bool) ($img['isMain'] ?? false), (int) ($img['order'] ?? 0)];
                }
            }
        }
        uasort($imageIds, fn ($a, $b) => [$b[0], -$b[1]] <=> [$a[0], -$a[1]]);
        $images = array_map(fn ($id) => $this->service->imageUrl($id), array_keys($imageIds));

        $stockTotal = 0;
        foreach ($variants as $v) {
            $stockTotal += $v['stock_quantity'];
        }

        $categories = $item['categories'] ?? [];
        $categoryNames = array_values(array_filter(array_map(fn ($c) => $c['name'] ?? null, $categories)));

        return [
            'platform_product_id' => (string) ($item['id'] ?? ''),
            'name' => $item['name'] ?? '',
            'description' => ! empty($item['description']) ? $item['description'] : null,
            'sku' => $first['sku'] ?? null,
            'barcode' => ($first['barcodeList'] ?? [])[0] ?? null,
            'brand' => $item['brand']['name'] ?? null,
            'category' => $categoryNames[0] ?? null,
            'category_path' => $categoryNames ? implode(' > ', $categoryNames) : null,
            'price' => $firstPrice['sell'],
            'discounted_price' => $firstPrice['discount'],
            'cost_price' => $firstPrice['buy'],
            'currency' => $firstPrice['currency'] ?? 'TRY',
            'tax_rate' => null,
            'stock_quantity' => (int) ($item['totalStock'] ?? $stockTotal),
            'images' => $images,
            'url' => $this->productUrl($item),
            'is_active' => empty($item['deleted']),
            'platform_created_at' => IkasService::msToDate($item['createdAt'] ?? null),
            'platform_updated_at' => IkasService::msToDate($item['updatedAt'] ?? null),
            'specifications' => null,
            'google_product_category' => ! empty($item['googleTaxonomyId']) ? (string) $item['googleTaxonomyId'] : null,
            'metadata' => array_filter([
                'ikas_slug' => $item['metaData']['slug'] ?? null,
                'ikas_short_description' => $item['shortDescription'] ?? null,
                'ikas_tags' => array_values(array_filter(array_map(fn ($tg) => $tg['name'] ?? null, $item['tags'] ?? []))) ?: null,
                'ikas_brand_id' => $item['brand']['id'] ?? null,
                'ikas_category_ids' => array_values(array_filter(array_map(fn ($c) => $c['id'] ?? null, $categories))) ?: null,
            ]),
            'variants' => $variants,
        ];
    }

    /** Storefront ürün URL'i: {storefront_url}/{metaData.slug} (ikas standart yapısı, 30 Eyl 2026 doğrulandı). */
    protected function productUrl(array $item): ?string
    {
        $slug = trim((string) ($item['metaData']['slug'] ?? ''));
        $base = $this->service->storefrontUrl();

        return ($slug !== '' && $base) ? $base . '/' . ltrim($slug, '/') : null;
    }

    protected function mapVariant(array $v): array
    {
        $price = $this->pickPrice($v['prices'] ?? []);

        $stock = 0;
        foreach ($v['stocks'] ?? [] as $s) {
            $stock += (float) ($s['stockCount'] ?? 0);
        }

        return [
            'platform_variant_id' => (string) ($v['id'] ?? ''),
            'sku' => $v['sku'] ?? null,
            'barcode' => ($v['barcodeList'] ?? [])[0] ?? null,
            'price' => $price['sell'],
            'discounted_price' => $price['discount'],
            'cost_price' => $price['buy'],
            'stock_quantity' => (int) $stock,
            'attributes' => [],
            'is_active' => (bool) ($v['isActive'] ?? true),
        ];
    }

    /** Varsayılan fiyat listesi (priceListId=null) tercihli fiyat seçimi. */
    protected function pickPrice(array $prices): array
    {
        $chosen = null;
        foreach ($prices as $p) {
            if (empty($p['priceListId'])) {
                $chosen = $p;
                break;
            }
        }
        $chosen ??= $prices[0] ?? [];

        return [
            'sell' => isset($chosen['sellPrice']) ? (float) $chosen['sellPrice'] : null,
            'discount' => isset($chosen['discountPrice']) && $chosen['discountPrice'] !== null ? (float) $chosen['discountPrice'] : null,
            'buy' => isset($chosen['buyPrice']) && $chosen['buyPrice'] !== null ? (float) $chosen['buyPrice'] : null,
            'currency' => $chosen['currencyCode'] ?? 'TRY',
        ];
    }
}
