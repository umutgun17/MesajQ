<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('channel:id,name,type');

        // Search (name, SKU, barcode)
        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhere('barcode', 'ilike', "%{$search}%");
            });
        }

        // Filters
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('channel_id')) {
            $query->where('channel_id', (int) $request->channel_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('in_stock')) {
            $inStock = filter_var($request->in_stock, FILTER_VALIDATE_BOOLEAN);
            $query->where('stock_quantity', $inStock ? '>' : '=', 0);
        }

        if ($request->filled('trendyol_status')) {
            if ($request->trendyol_status === 'mapped') {
                $query->whereRaw("metadata->>'trendyol_content_id' IS NOT NULL");
            } elseif ($request->trendyol_status === 'unmapped') {
                $query->whereRaw("metadata->>'trendyol_content_id' IS NULL");
            }
        }

        // Sorting
        $allowedSorts = ['name', 'price', 'stock_quantity', 'created_at', 'updated_at', 'brand', 'platform_created_at'];
        $sortBy = in_array($request->sort_by, $allowedSorts) ? $request->sort_by : 'platform_created_at';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw("{$sortBy} {$sortDir} NULLS LAST");

        $perPage = min((int) ($request->per_page ?? 25), 100);

        return response()->json($query->paginate($perPage));
    }

    public function show(string $id): JsonResponse
    {
        $product = Product::with(['variants', 'channel'])->find($id);

        // Fallback: try platform_product_id
        if (! $product) {
            $product = Product::with(['variants', 'channel'])
                ->where('platform_product_id', $id)
                ->first();
        }

        if (! $product) {
            abort(404);
        }

        $data = $product->toArray();
        unset($data['channel']['credentials']);

        // Sort variants by Beden (size) naturally: numeric first, then text sizes
        if (! empty($data['variants'])) {
            $sizeOrder = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, '2XL' => 6, '3XL' => 7];
            usort($data['variants'], function ($a, $b) use ($sizeOrder) {
                $aSize = $a['attributes']['Beden'] ?? '';
                $bSize = $b['attributes']['Beden'] ?? '';

                $aNum = is_numeric($aSize) ? (float) $aSize : null;
                $bNum = is_numeric($bSize) ? (float) $bSize : null;

                // Both numeric
                if ($aNum !== null && $bNum !== null) {
                    return $aNum <=> $bNum;
                }
                // Both text-based sizes
                $aOrd = $sizeOrder[strtoupper($aSize)] ?? null;
                $bOrd = $sizeOrder[strtoupper($bSize)] ?? null;
                if ($aOrd !== null && $bOrd !== null) {
                    return $aOrd <=> $bOrd;
                }
                // Numeric before text
                if ($aNum !== null) return -1;
                if ($bNum !== null) return 1;

                return strnatcasecmp($aSize, $bSize);
            });
        }

        // Build external URLs for Ticimax channels
        if ($product->channel && str_starts_with($product->channel->type, 'ticimax')) {
            $wsdlUrl = $product->channel->credentials['wsdl_url'] ?? '';
            $siteBase = preg_replace('/\/Servis\/.*$/i', '', $wsdlUrl);

            if ($siteBase) {
                $productUrl = $product->url;
                if ($productUrl && ! str_starts_with($productUrl, 'http')) {
                    $productUrl = rtrim($siteBase, '/') . '/' . ltrim($productUrl, '/');
                }
                $data['storefront_url'] = $productUrl;
                $data['admin_url'] = $siteBase . '/Admin/UrunIslemleri.aspx?ID=' . $product->platform_product_id;
            }
        } elseif ($product->channel && str_starts_with($product->channel->type, 'ikas')) {
            $store = $product->channel->credentials['store_name'] ?? '';
            if ($store) {
                $data['admin_url'] = "https://{$store}.myikas.com/admin/products/{$product->platform_product_id}";
            }
        }

        return response()->json($data);
    }

    public function stats(): JsonResponse
    {
        $stats = Product::selectRaw("
            count(*) as total,
            count(*) filter (where is_active = true) as active,
            count(*) filter (where is_active = false) as inactive,
            count(*) filter (where stock_quantity <= 0) as out_of_stock,
            count(distinct brand) filter (where brand is not null) as brands_count
        ")->first();

        $totalVariants = \App\Models\ProductVariant::whereHas('product')->count();

        return response()->json([
            'total' => (int) $stats->total,
            'active' => (int) $stats->active,
            'inactive' => (int) $stats->inactive,
            'out_of_stock' => (int) $stats->out_of_stock,
            'total_variants' => $totalVariants,
            'brands_count' => (int) $stats->brands_count,
        ]);
    }

    public function brands(): JsonResponse
    {
        $brands = Product::whereNotNull('brand')
            ->where('brand', '!=', '')
            ->select('brand')
            ->selectRaw('count(*) as count')
            ->groupBy('brand')
            ->orderBy('brand')
            ->get();

        return response()->json($brands);
    }

    public function categories(): JsonResponse
    {
        $categories = Product::whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category')
            ->selectRaw('count(*) as count')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        return response()->json($categories);
    }
}
