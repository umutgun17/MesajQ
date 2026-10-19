<?php

namespace App\Console\Commands;

use App\Adapters\EcommercePlatformFactory;
use App\Adapters\TicimaxEcommerceAdapter;
use App\Models\Channel;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncCrmProducts extends Command
{
    protected $signature = 'crm:sync-products
                            {--limit=100 : Sayfa basi kayit sayisi}
                            {--pages=0 : Max sayfa (0=hepsi)}
                            {--channel= : Belirli kanal ID}
                            {--full : Tum urunleri bastan cek}';

    protected $description = 'E-ticaret platformlarindan urunleri senkronize eder';

    protected int $totalNew = 0;
    protected int $totalUpdated = 0;
    protected int $totalVariants = 0;

    public function handle(): int
    {
        $query = Channel::withoutGlobalScopes()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('type', 'like', '%-ecommerce');
            });

        if ($channelId = $this->option('channel')) {
            $query->where('id', (int) $channelId);
        }

        $channels = $query->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif e-ticaret kanali bulunamadi.');
            return 1;
        }

        foreach ($channels as $channel) {
            try {
                $adapter = EcommercePlatformFactory::create($channel);
            } catch (\InvalidArgumentException $e) {
                $this->warn("Kanal #{$channel->id}: {$e->getMessage()}");
                continue;
            }

            $this->info("Kanal #{$channel->id} ({$channel->type}, Tenant #{$channel->tenant_id}) isleniyor...");

            $this->syncProducts($adapter, $channel);

            // Variant fallback: some Ticimax products are not returned by SelectUrun
            // but their variants exist in SelectVaryasyon. Sync these via fallback.
            if ($adapter instanceof TicimaxEcommerceAdapter && $this->option('full')) {
                $this->syncMissingFromVariants($adapter, $channel);
            }

            $freshSettings = $channel->fresh()->settings ?? [];
            $freshSettings['products_synced_at'] = now()->toIso8601String();
            $channel->update([
                'last_synced_at' => now(),
                'settings' => $freshSettings,
            ]);
        }

        $this->info("Tamamlandi: {$this->totalNew} yeni, {$this->totalUpdated} guncellendi, {$this->totalVariants} varyant");
        Log::info('SyncCrmProducts: completed', [
            'new' => $this->totalNew,
            'updated' => $this->totalUpdated,
            'variants' => $this->totalVariants,
        ]);

        return 0;
    }

    protected function syncProducts($adapter, Channel $channel): void
    {
        $limit = (int) $this->option('limit');
        $maxPages = (int) $this->option('pages');
        $isFull = $this->option('full');

        $filters = [];

        // Incremental sync: only fetch products updated since last sync
        $lastSyncedAt = $channel->settings['products_synced_at'] ?? $channel->last_synced_at;
        if (! $isFull && $lastSyncedAt) {
            $ts = $lastSyncedAt instanceof \Carbon\Carbon ? $lastSyncedAt : \Carbon\Carbon::parse($lastSyncedAt);
            $filters['DuzenlemeTarihiBaslangic'] = $ts->format('Y-m-d\TH:i:s');
        }

        $page = 1;
        while (true) {
            $result = $adapter->getProducts($filters, $page, $limit);
            $products = $result['products'] ?? [];
            $total = $result['total'] ?? 0;

            if (empty($products)) {
                if ($page === 1) {
                    $this->info('  Hic urun bulunamadi.');
                }
                break;
            }

            foreach ($products as $productData) {
                $this->syncProduct($productData, $channel);
            }

            $this->info("  Sayfa {$page}: " . count($products) . " urun islendi (toplam: {$total})");

            $page++;

            if ($maxPages > 0 && ($page - 1) >= $maxPages) {
                break;
            }

            if (count($products) < $limit) {
                break;
            }

            usleep(300000); // 0.3s rate limit
        }
    }

    protected function syncProduct(array $data, Channel $channel): void
    {
        $platformId = $data['platform_product_id'] ?? '';
        if (empty($platformId)) {
            return;
        }

        $variants = $data['variants'] ?? [];
        unset($data['variants']);

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('platform_product_id', $platformId)
            ->first();

        if ($product) {
            // Don't overwrite existing specifications with null (API rate limit protection)
            if (array_key_exists('specifications', $data) && $data['specifications'] === null && $product->specifications !== null) {
                unset($data['specifications']);
            }
            // Platform Google kategorisi vermiyorsa (ikas googleTaxonomyId boş) Yunbi'nin yazdığı sınıflandırmayı koru
            if (array_key_exists('google_product_category', $data) && $data['google_product_category'] === null && $product->google_product_category !== null) {
                unset($data['google_product_category']);
            }
            $product->update($data);
            $this->totalUpdated++;
        } else {
            $data['tenant_id'] = $channel->tenant_id;
            $data['channel_id'] = $channel->id;
            $product = Product::withoutGlobalScopes()->create($data);
            $this->totalNew++;
        }

        // Sync variants
        $this->syncVariants($product, $variants);
    }

    /**
     * Find products that exist in variant API but not in product card API,
     * and sync them using variant data as fallback.
     */
    protected function syncMissingFromVariants(TicimaxEcommerceAdapter $adapter, Channel $channel): void
    {
        // Get all variant UrunKartiIDs from the platform
        $allVariantProductIds = [];
        $page = 1;

        while (true) {
            $result = $adapter->getService()->getVariants([], $page, 100);

            if (! $result['success']) {
                break;
            }

            $variants = $result['data']['SelectVaryasyonResult']['Varyasyon'] ?? [];
            if (empty($variants)) {
                break;
            }

            if (isset($variants['ID'])) {
                $variants = [$variants];
            }

            foreach ($variants as $v) {
                $pId = (string) ($v['UrunKartiID'] ?? 0);
                if ($pId && $pId !== '0') {
                    $allVariantProductIds[$pId] = true;
                }
            }

            if (count($variants) < 100) {
                break;
            }

            $page++;
            usleep(200000);
        }

        // Find which product IDs we don't have in our DB
        $existingIds = Product::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->pluck('platform_product_id')
            ->flip()
            ->toArray();

        $missingIds = array_diff(array_keys($allVariantProductIds), array_keys($existingIds));

        if (empty($missingIds)) {
            return;
        }

        $this->info("  Varyant API fallback: " . count($missingIds) . " eksik urun tespit edildi");

        // Build brand lookup
        $brandLookup = Product::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->whereNotNull('brand')
            ->whereRaw("(metadata->>'marka_id') IS NOT NULL")
            ->selectRaw("DISTINCT (metadata->>'marka_id')::int as marka_id, brand")
            ->get()
            ->pluck('brand', 'marka_id')
            ->toArray();

        // Fetch missing products via variant API
        $products = $adapter->getProductsFromVariants($missingIds, $brandLookup);

        foreach ($products as $productData) {
            // Resolve product name from sibling (same StokKodu base)
            if (empty($productData['name'])) {
                $sku = $productData['sku'] ?? '';
                $firstVariantSku = $productData['variants'][0]['sku'] ?? '';
                $productData['name'] = $this->resolveProductName($channel, $sku, $firstVariantSku);
            }

            // Resolve URL from sibling or derive from name (variant API doesn't return UrunSayfaAdresi)
            if (empty($productData['url'])) {
                $sku = $productData['sku'] ?? '';
                $platformId = $productData['platform_product_id'] ?? '';
                $productData['url'] = $this->resolveProductUrl($channel, $sku, $platformId, $productData['name'] ?? '');
            }

            $this->syncProduct($productData, $channel);
        }

        $this->info("  Varyant API fallback: " . count($products) . " urun senkronize edildi");
    }

    /**
     * Derive product URL from sibling (same model code) or fall back to name-based slug.
     * Ticimax URL format: /{slug}-{platform_product_id}
     */
    protected function resolveProductUrl(Channel $channel, string $sku, string $platformId, string $name): ?string
    {
        // 2026-07-02: URL UYDURMA KAPATILDI. Ticimax tam slug ile yönlendirir (id yetmez;
        // canlıda doğrulandı: bozuk slug + geçerli id → 404). Kardeş slug'ı/isimden slug
        // türetmek her zaman 404 üretir. Bu ürünler SelectUrun'da da yok = sitede ürün
        // kartı yayında değil → url NULL kalır; arama/öneri yüzeyleri URL'siz ürünü göstermez.
        return null;
    }

    /** @deprecated 404 üretiyordu — bkz. resolveProductUrl notu. */
    protected function legacyResolveProductUrl(Channel $channel, string $sku, string $platformId, string $name): ?string
    {
        if (! $platformId) {
            return null;
        }

        // Extract model code from SKU: NEBIMV3|MODEL|COLOR|SIZE
        $parts = explode('|', $sku);
        $modelCode = $parts[1] ?? null;

        if ($modelCode) {
            $sibling = Product::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('sku', 'like', "%|{$modelCode}|%")
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->where('platform_product_id', '!=', $platformId)
                ->first(['url']);

            if ($sibling) {
                return preg_replace('/-\d+$/', '-' . $platformId, $sibling->url);
            }
        }

        // Fall back to name-based slug
        if ($name) {
            $tr = ['ç' => 'c', 'Ç' => 'c', 'ş' => 's', 'Ş' => 's', 'ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o'];
            $slug = strtr($name, $tr);
            $slug = strtolower($slug);
            $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
            $slug = preg_replace('/[\s-]+/', '-', trim($slug));
            return '/' . $slug . '-' . $platformId;
        }

        return null;
    }

    /**
     * Try to derive a product name from sibling products with the same StokKodu.
     */
    protected function resolveProductName(Channel $channel, string $sku, string $variantSku): string
    {
        if (! empty($variantSku)) {
            $sibling = Product::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->whereHas('variants', fn ($q) => $q->where('sku', $variantSku))
                ->first();

            if ($sibling) {
                return $sibling->name;
            }
        }

        // Try base SKU
        if (! empty($sku)) {
            $baseSku = explode('|', $sku)[1] ?? $sku;
            $sibling = Product::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('sku', 'like', '%|' . $baseSku . '%')
                ->first();

            if ($sibling) {
                return $sibling->name;
            }

            return $baseSku;
        }

        return 'Bilinmeyen Urun';
    }

    protected function syncVariants(Product $product, array $variants): void
    {
        if (empty($variants)) {
            return;
        }

        $existingVariantIds = $product->variants()->pluck('platform_variant_id')->toArray();
        $incomingVariantIds = [];

        foreach ($variants as $variantData) {
            $variantPlatformId = $variantData['platform_variant_id'] ?? '';
            if (empty($variantPlatformId)) {
                continue;
            }

            $incomingVariantIds[] = $variantPlatformId;

            $existing = $product->variants()
                ->where('platform_variant_id', $variantPlatformId)
                ->first();

            if ($existing) {
                $existing->update($variantData);
            } else {
                $variantData['product_id'] = $product->id;
                $variantData['tenant_id'] = $product->tenant_id;
                ProductVariant::create($variantData);
            }

            $this->totalVariants++;
        }

        // Remove variants that no longer exist on platform
        $toDelete = array_diff($existingVariantIds, $incomingVariantIds);
        if (! empty($toDelete)) {
            $product->variants()
                ->whereIn('platform_variant_id', $toDelete)
                ->delete();
        }
    }
}
