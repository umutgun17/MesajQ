<?php

namespace App\Adapters;

use App\Contracts\EcommercePlatformInterface;
use App\Services\TicimaxService;
use Illuminate\Support\Facades\Log;

class TicimaxEcommerceAdapter implements EcommercePlatformInterface
{
    protected ?array $categoryLookup = null;
    protected ?array $specPropertyLookup = null;
    protected ?array $specValueLookup = null;

    public function __construct(protected TicimaxService $service) {}

    public function getService(): TicimaxService
    {
        return $this->service;
    }

    public function getProducts(array $filters, int $page, int $pageSize): array
    {
        $result = $this->service->getProductCards($filters, $page, $pageSize);

        if (! $result['success']) {
            return ['products' => [], 'total' => 0];
        }

        $data = $result['data']['SelectUrunResult'] ?? [];
        $items = $data['UrunKarti'] ?? [];

        // Single item → wrap in array
        if (! empty($items) && isset($items['ID'])) {
            $items = [$items];
        }

        $products = array_map(fn ($item) => $this->mapProduct($item), $items);

        return [
            'products' => $products,
            'total' => count($products),
        ];
    }

    public function getProductCount(array $filters): int
    {
        $result = $this->service->getProductCount($filters);

        if (! $result['success']) {
            return 0;
        }

        return (int) ($result['data']['SelectUrunCountResult'] ?? 0);
    }

    public function getCategories(): array
    {
        $result = $this->service->getCategories();

        if (! $result['success']) {
            return [];
        }

        $items = $result['data']['SelectKategoriResult']['Kategori'] ?? [];

        if (! empty($items) && isset($items['KategoriId'])) {
            $items = [$items];
        }

        return array_map(fn ($item) => [
            'id' => $item['KategoriId'] ?? null,
            'name' => $item['KategoriAdi'] ?? '',
            'parent_id' => $item['UstKategoriId'] ?? null,
        ], $items);
    }

    public function getBrands(): array
    {
        $result = $this->service->getBrands();

        if (! $result['success']) {
            return [];
        }

        $items = $result['data']['SelectMarkaResult']['Marka'] ?? [];

        if (! empty($items) && isset($items['MarkaId'])) {
            $items = [$items];
        }

        return array_map(fn ($item) => [
            'id' => $item['MarkaId'] ?? null,
            'name' => $item['MarkaAdi'] ?? '',
        ], $items);
    }

    protected function mapProduct(array $item): array
    {
        // ── Images: Resimler.string[] (array of URL strings) ──
        $images = [];
        $resimler = $item['Resimler']['string'] ?? [];
        if (is_string($resimler)) {
            $resimler = [$resimler];
        }
        foreach ($resimler as $url) {
            if (! empty($url) && is_string($url)) {
                $images[] = $url;
            }
        }

        // ── Variants: Varyasyonlar.Varyasyon[] ──
        $variants = [];
        $varyantlar = $item['Varyasyonlar']['Varyasyon'] ?? [];
        if (! empty($varyantlar) && isset($varyantlar['ID'])) {
            $varyantlar = [$varyantlar];
        }
        foreach ($varyantlar as $v) {
            $variants[] = $this->mapVariant($v);
        }

        // ── Prices & barcode from first variant (product card has no price fields) ──
        $firstVariant = $varyantlar[0] ?? null;
        $price = $firstVariant ? $this->parseDecimal($firstVariant['SatisFiyati'] ?? null) : null;
        $discountedPrice = $firstVariant ? $this->parseDecimal($firstVariant['IndirimliFiyati'] ?? null) : null;
        $costPrice = $firstVariant ? $this->parseDecimal($firstVariant['AlisFiyati'] ?? null) : null;
        $taxRate = $firstVariant ? $this->parseDecimal($firstVariant['KdvOrani'] ?? null) : null;
        $barcode = $firstVariant['Barkod'] ?? null;

        // SKU: TedarikciKodu or OzelAlan5
        $sku = $item['TedarikciKodu'] ?? $item['OzelAlan5'] ?? null;

        return [
            'platform_product_id' => (string) ($item['ID'] ?? ''),
            'name' => $item['UrunAdi'] ?? '',
            'description' => ! empty($item['Aciklama']) ? $item['Aciklama'] : null,
            'sku' => $sku,
            'barcode' => ! empty($barcode) ? $barcode : null,
            'brand' => $item['Marka'] ?? null,
            'category' => $item['AnaKategori'] ?? null,
            'category_path' => $this->buildCategoryPath((int) ($item['AnaKategoriID'] ?? 0)),
            'price' => $price,
            'discounted_price' => $discountedPrice,
            'cost_price' => $costPrice,
            'currency' => 'TRY',
            'tax_rate' => $taxRate,
            'stock_quantity' => (int) ($item['ToplamStokAdedi'] ?? 0),
            'images' => $images,
            'url' => $item['UrunSayfaAdresi'] ?? null,
            'is_active' => (bool) ($item['Aktif'] ?? false),
            'platform_created_at' => $this->parseDate($item['EklemeTarihi'] ?? null),
            'platform_updated_at' => $this->parseDate($item['YayinTarihi'] ?? null),
            'specifications' => $this->resolveSpecifications($item),
            'google_product_category' => ! empty($item['AdwordsKategori']) ? $item['AdwordsKategori'] : null,
            'metadata' => array_filter([
                'ana_kategori_id' => $item['AnaKategoriID'] ?? null,
                'marka_id' => $item['MarkaID'] ?? null,
                'tedarikci_id' => $item['TedarikciID'] ?? null,
                'tedarikci_kodu' => $item['TedarikciKodu'] ?? null,
                'entegrasyon_id' => $item['EntegrasyonID'] ?? null,
                'satis_birimi' => $item['SatisBirimi'] ?? null,
                // Ticimax özel alanları — kampanya rozeti vb. (ör. OzelAlan1 = "1 ALANA 1 BEDAVA");
                // arama widget'ı kartlarda gösterir (config search.presentation.badge_metadata_key)
                'ozel_alan1' => $this->cleanCustomField($item['OzelAlan1'] ?? null),
                'ozel_alan2' => $this->cleanCustomField($item['OzelAlan2'] ?? null),
                'ozel_alan3' => $this->cleanCustomField($item['OzelAlan3'] ?? null),
                'ozel_alan4' => $this->cleanCustomField($item['OzelAlan4'] ?? null),
            ], fn ($v) => $v !== null && $v !== '' && $v !== '0' && $v !== 0),
            'variants' => $variants,
        ];
    }

    /** Ticimax özel alanını temizle: HTML etiketleri at, kırp; boşsa null. */
    protected function cleanCustomField($value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $v = trim(strip_tags((string) $value));

        return $v !== '' ? mb_substr($v, 0, 120) : null;
    }

    /**
     * Fetch products that SelectUrun misses but SelectVaryasyon returns.
     * Returns product data reconstructed from variant-level information.
     *
     * @param  array  $missingPlatformIds  Platform product IDs not returned by SelectUrun
     * @param  array  $brandLookup  MarkaID => brand name mapping
     */
    public function getProductsFromVariants(array $missingPlatformIds, array $brandLookup = []): array
    {
        $products = [];
        $categoryLookup = $this->getCategoryLookup();

        foreach ($missingPlatformIds as $platformId) {
            $result = $this->service->getVariants(
                ['UrunKartiID' => (int) $platformId],
                1,
                100
            );

            if (! $result['success']) {
                continue;
            }

            $variants = $result['data']['SelectVaryasyonResult']['Varyasyon'] ?? [];
            if (empty($variants)) {
                continue;
            }

            if (isset($variants['ID'])) {
                $variants = [$variants];
            }

            $firstVariant = $variants[0];

            // Map variants
            $mappedVariants = [];
            $totalStock = 0;
            foreach ($variants as $v) {
                $mappedVariants[] = $this->mapVariant($v);
                $totalStock += (int) ($v['StokAdedi'] ?? 0);
            }

            // Brand from MarkaID
            $markaId = (int) ($firstVariant['MarkaID'] ?? 0);
            $brand = $brandLookup[$markaId] ?? null;

            // Category from variant Kategoriler
            $category = null;
            $categoryPath = null;
            $kategoriler = $firstVariant['Kategoriler']['VaryasyonKategori'] ?? $firstVariant['Kategoriler'] ?? [];
            $catId = 0;
            if (is_array($kategoriler)) {
                if (isset($kategoriler['KategoriId'])) {
                    $catId = (int) $kategoriler['KategoriId'];
                } elseif (! empty($kategoriler)) {
                    $first = reset($kategoriler);
                    if (is_array($first)) {
                        $catId = (int) ($first['KategoriId'] ?? 0);
                    }
                }
            }
            if ($catId > 0 && isset($categoryLookup[$catId])) {
                $category = $categoryLookup[$catId]['name'];
                $categoryPath = $this->buildCategoryPath($catId);
            }

            $products[] = [
                'platform_product_id' => (string) $platformId,
                'name' => null, // Will be resolved by sync command from sibling products
                'sku' => $firstVariant['TedarikciKodu'] ?? null,
                'barcode' => ! empty($firstVariant['Barkod']) ? $firstVariant['Barkod'] : null,
                'brand' => $brand,
                'category' => $category,
                'category_path' => $categoryPath,
                'price' => $this->parseDecimal($firstVariant['SatisFiyati'] ?? null),
                'discounted_price' => $this->parseDecimal($firstVariant['IndirimliFiyati'] ?? null),
                'cost_price' => $this->parseDecimal($firstVariant['AlisFiyati'] ?? null),
                'currency' => 'TRY',
                'tax_rate' => $this->parseDecimal($firstVariant['KdvOrani'] ?? null),
                'stock_quantity' => $totalStock,
                'images' => [],
                'is_active' => (bool) ($firstVariant['UrunKartiAktif'] ?? false),
                'metadata' => array_filter([
                    'marka_id' => $markaId > 0 ? $markaId : null,
                    'tedarikci_kodu' => $firstVariant['TedarikciKodu'] ?? null,
                    'source' => 'variant_api_fallback',
                ], fn ($v) => $v !== null && $v !== '' && $v !== 0),
                'variants' => $mappedVariants,
            ];

            usleep(100000); // 100ms rate limit
        }

        return $products;
    }

    protected function resolveSpecifications(array $item): ?array
    {
        $detaylar = $item['TeknikDetaylar']['UrunKartiTeknikDetay'] ?? [];

        if (empty($detaylar)) {
            return null;
        }

        // Single item → wrap in array
        if (isset($detaylar['OzellikID'])) {
            $detaylar = [$detaylar];
        }

        $lookups = $this->getSpecLookups();

        // If lookups failed (API rate limit etc.), skip — don't overwrite existing specs with null
        if (empty($lookups['properties']) || empty($lookups['values'])) {
            return null;
        }

        $specs = [];

        foreach ($detaylar as $d) {
            $propId = (int) ($d['OzellikID'] ?? 0);
            $valId = (int) ($d['DegerID'] ?? 0);

            if ($propId <= 0 || $valId <= 0) {
                continue;
            }

            $propName = $lookups['properties'][$propId] ?? null;
            $valText = $lookups['values'][$valId] ?? null;

            if ($propName && $valText) {
                $specs[$propName] = $valText;
            }
        }

        return ! empty($specs) ? $specs : null;
    }

    protected function getSpecLookups(): array
    {
        if ($this->specPropertyLookup !== null && $this->specValueLookup !== null) {
            return ['properties' => $this->specPropertyLookup, 'values' => $this->specValueLookup];
        }

        // Retry up to 3 times with wait for rate limit
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Fetch property names (OzellikID → name)
            $this->specPropertyLookup = [];
            $propResult = $this->service->getTechSpecProperties();

            if (! $propResult['success']) {
                Log::warning('TicimaxEcommerceAdapter: getTechSpecProperties failed, attempt ' . $attempt, [
                    'error' => $propResult['error'] ?? 'unknown',
                ]);
                sleep(65); // Wait for rate limit to clear
                continue;
            }

            $propItems = $propResult['data']['SelectTeknikDetayOzellikResult']['TeknikDetayOzellik'] ?? [];

            if (! empty($propItems) && isset($propItems['ID'])) {
                $propItems = [$propItems];
            }

            foreach ($propItems as $p) {
                $id = (int) ($p['ID'] ?? 0);
                if ($id > 0) {
                    $this->specPropertyLookup[$id] = $p['Tanim'] ?? '';
                }
            }

            // Rate limit pause between API calls
            sleep(2);

            // Fetch value texts (DegerID → value string)
            $this->specValueLookup = [];
            $valResult = $this->service->getTechSpecValues();

            if (! $valResult['success']) {
                Log::warning('TicimaxEcommerceAdapter: getTechSpecValues failed, attempt ' . $attempt, [
                    'error' => $valResult['error'] ?? 'unknown',
                ]);
                $this->specPropertyLookup = null;
                $this->specValueLookup = null;
                sleep(65);
                continue;
            }

            $valItems = $valResult['data']['SelectTeknikDetayDegerResult']['TeknikDetayDeger'] ?? [];

            if (! empty($valItems) && isset($valItems['ID'])) {
                $valItems = [$valItems];
            }

            foreach ($valItems as $v) {
                $id = (int) ($v['ID'] ?? 0);
                if ($id > 0) {
                    $this->specValueLookup[$id] = $v['Tanim'] ?? '';
                }
            }

            // Success — break out of retry loop
            break;
        }

        return ['properties' => $this->specPropertyLookup, 'values' => $this->specValueLookup];
    }

    protected function mapVariant(array $v): array
    {
        // Attributes: Ozellikler.VaryasyonOzellik[].Tanim / Deger
        $attributes = [];
        $ozellikler = $v['Ozellikler']['VaryasyonOzellik'] ?? [];
        if (! empty($ozellikler) && isset($ozellikler['Tanim'])) {
            $ozellikler = [$ozellikler];
        }
        foreach ($ozellikler as $oz) {
            $name = $oz['Tanim'] ?? '';
            $value = $oz['Deger'] ?? '';
            if (! empty($name) && $value !== '') {
                $attributes[$name] = $value;
            }
        }

        return [
            'platform_variant_id' => (string) ($v['ID'] ?? ''),
            'sku' => $v['StokKodu'] ?? null,
            'barcode' => $v['Barkod'] ?? null,
            'price' => $this->parseDecimal($v['SatisFiyati'] ?? null),
            'discounted_price' => $this->parseDecimal($v['IndirimliFiyati'] ?? null),
            'cost_price' => $this->parseDecimal($v['AlisFiyati'] ?? null),
            'stock_quantity' => (int) ($v['StokAdedi'] ?? 0),
            'attributes' => $attributes,
            'is_active' => (bool) ($v['Aktif'] ?? false),
        ];
    }

    protected function buildCategoryPath(int $categoryId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        $lookup = $this->getCategoryLookup();
        $parts = [];
        $id = $categoryId;

        while ($id > 0 && isset($lookup[$id])) {
            $parts[] = $lookup[$id]['name'];
            $id = $lookup[$id]['parent'];
        }

        return ! empty($parts) ? implode(' > ', array_reverse($parts)) : null;
    }

    protected function getCategoryLookup(): array
    {
        if ($this->categoryLookup !== null) {
            return $this->categoryLookup;
        }

        $result = $this->service->getCategories();
        $items = $result['data']['SelectKategoriResult']['Kategori'] ?? [];

        $this->categoryLookup = [];
        foreach ($items as $cat) {
            $id = (int) ($cat['ID'] ?? 0);
            if ($id > 0) {
                $this->categoryLookup[$id] = [
                    'name' => $cat['Tanim'] ?? '',
                    'parent' => (int) ($cat['PID'] ?? 0),
                ];
            }
        }

        return $this->categoryLookup;
    }

    protected function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $val = round((float) $value, 2);

        return $val > 0 ? $val : null;
    }

    protected function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value, 'Europe/Istanbul')->utc()->toDateTimeString();
        } catch (\Exception) {
            return null;
        }
    }
}
