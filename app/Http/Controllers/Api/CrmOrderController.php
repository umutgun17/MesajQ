<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Crm\OrderPricingBreakdown;
use App\Services\Crm\PaymentMethod;
use App\Services\OrderDelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CrmOrderController extends Controller
{
    /**
     * Trendyol panelindeki gibi durum sekmeleri. 'delayed' durum değil,
     * OrderDelayService hesabıdır (söz verilen günü geçmiş açık siparişler).
     */
    public const TABS = [
        'all' => null,
        'new' => [0, 1, 2],
        'processing' => [4, 5],
        'shipped' => [6, 20, 21],
        'delivered' => [7],
        'undelivered' => [18],
        'cancelled' => [8],
        'returns' => [9, 11, 12, 13, 16, 17],
        'delayed' => null,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Order::with('channel:id,name,type');

        $this->applyFilters($query, $request);

        // Sorting
        $allowedSorts = ['order_date', 'total_amount', 'status', 'order_no', 'created_at'];
        $sortBy = in_array($request->sort_by, $allowedSorts) ? $request->sort_by : 'order_date';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw("{$sortBy} {$sortDir} NULLS LAST");

        $perPage = min((int) ($request->per_page ?? 30), 100);

        if (! $request->boolean('with_items')) {
            return response()->json($query->paginate($perPage));
        }

        // Liste kartı için gereken sütunlar (payments/billing_address/metadata gibi ağır
        // JSON alanları detay sayfasında) — yanıt ~%40 küçülür.
        $query->select([
            'id', 'tenant_id', 'channel_id', 'customer_id', 'order_no', 'status', 'status_label',
            'total_amount', 'paid_amount', 'payment_status', 'order_source', 'order_date',
            'customer_name', 'customer_email', 'cargo_company', 'cargo_tracking_no',
            'cargo_tracking_url', 'items_count', 'shipping_address', 'status_updated_at',
            // Toplam açıklaması (indirim/kupon/kargo/vade kırılımı) için — yanıtta pricing'e dönüşür
            'subtotal', 'discount_amount', 'shipping_cost', 'gift_card_amount', 'coupon_code',
            'points_discount', 'tax_amount', 'payments', 'metadata',
        ]);
        $page = $query->with(['items' => fn ($q) => $q
            ->select('id', 'order_id', 'product_id', 'product_name', 'variant_info', 'sku', 'barcode', 'quantity', 'unit_price', 'total_price', 'image_url', 'status', 'status_label')
            ->orderBy('id')])
            ->paginate($perPage);
        $delay = $this->delayService($request);
        $tenantId = (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id'));
        (new OrderPricingBreakdown)->apply($page->getCollection(), $tenantId);
        $page->getCollection()->transform(function (Order $o) use ($delay) {
            $o->setAttribute('timeline', $delay?->timeline($o));
            $o->setAttribute('phone', $o->shipping_address['phone'] ?? null);
            $o->setAttribute('ship_city', $o->shipping_address['city'] ?? null);
            $o->setAttribute('ship_district', $o->shipping_address['district'] ?? null);
            $o->setAttribute('payment_method', PaymentMethod::classify($o->payments));
            $o->makeHidden(['shipping_address', 'payments', 'metadata']);

            return $o;
        });

        return response()->json($page);
    }

    /** Sekme adetleri — sekme dışındaki tüm filtrelerle. */
    public function tabCounts(Request $request): JsonResponse
    {
        $base = Order::query();
        $this->applyFilters($base, $request, false);

        $counts = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = [];
        foreach (self::TABS as $tab => $statuses) {
            if ($statuses === null) {
                continue;
            }
            $out[$tab] = (int) collect($statuses)->sum(fn ($st) => $counts[$st] ?? 0);
        }
        $out['all'] = (int) $counts->sum();
        $ids = $this->delayedIds($request);
        $out['delayed'] = $ids === [] ? 0 : (clone $base)->whereIn('id', $ids)->count();

        return response()->json($out);
    }

    /** Kargo firmaları (filtre listesi). */
    public function cargoCompanies(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id'));

        return response()->json(Cache::remember("crm:cargo-companies:{$tenantId}", 600, fn () =>
            Order::select('cargo_company')
                ->selectRaw('count(*) as order_count')
                ->whereNotNull('cargo_company')
                ->where('order_date', '>=', now()->subDays(90))
                ->groupBy('cargo_company')
                ->orderByDesc('order_count')
                ->get()
        ));
    }

    private function delayService(Request $request): ?OrderDelayService
    {
        $tenantId = (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id'));
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        return $tenant ? new OrderDelayService($tenant) : null;
    }

    /** @return int[] geciken sipariş id'leri */
    private function delayedIds(Request $request): array
    {
        // Gecikme hesabı tüm açık siparişleri dolaşır (~250 ms); sekme sayıları ve
        // Geciken sekmesi her filtre değişiminde istediği için 60 sn tenant bazlı önbellek.
        $tenantId = (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id'));

        return Cache::remember("crm:delayed-ids:{$tenantId}", 60, fn () => $this->delayService($request)?->delayedOrders()->pluck('order.id')->all() ?? []);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with(['items', 'customer', 'channel'])->findOrFail($id);

        $data = $order->toArray();

        if (isset($data['channel']['credentials'])) {
            unset($data['channel']['credentials']);
        }

        // Build admin URL for Ticimax
        if ($order->channel && str_starts_with($order->channel->type, 'ticimax')) {
            $wsdlUrl = $order->channel->credentials['wsdl_url'] ?? '';
            $siteBase = preg_replace('/\/Servis\/.*$/i', '', $wsdlUrl);
            if ($siteBase) {
                $data['admin_url'] = $siteBase . '/Admin/SiparisDetay.aspx?SID=' . $order->platform_order_id;
            }
        }

        return response()->json($data);
    }

    public function stats(Request $request): JsonResponse
    {
        $query = Order::query();
        $this->applyFilters($query, $request);

        // Tek taramada tüm sayılar (eskiden 7 ayrı sorgu)
        $from = now()->subDays(30);
        $row = $query->toBase()->selectRaw('
            count(*) as total,
            count(*) filter (where payment_status = 1) as paid_count,
            count(*) filter (where payment_status = 0) as unpaid_count,
            coalesce(sum(total_amount) filter (where payment_status = 1), 0) as total_revenue,
            count(*) filter (where order_date >= ?) as last_30_days,
            coalesce(sum(total_amount) filter (where payment_status = 1 and order_date >= ?), 0) as last_30_revenue
        ', [$from, $from])->first();

        $paidCount = (int) $row->paid_count;
        $totalRevenue = (float) $row->total_revenue;

        // Ürün filtresi varken: eşleşen kalemlerin adedi ("bugün TBA9060 kaç adet sattı?")
        $productQty = null;
        if ($request->filled('product')) {
            $product = $this->escapeLike(trim($request->product));
            $ids = (clone $query)->toBase()->select('id');
            $brandIds = $this->brandProductIds($product);
            $productQty = (int) DB::table('order_items')
                ->whereIn('order_id', $ids)
                ->where(function ($q) use ($product, $brandIds) {
                    $this->matchItem($q, $product);
                    if ($brandIds) {
                        $q->orWhere(fn ($q) => $this->matchBrand($q, $brandIds));
                    }
                })
                ->sum('quantity');
        }

        return response()->json([
            'product_qty' => $productQty,
            'total' => (int) $row->total,
            'total_revenue' => $totalRevenue,
            'paid_count' => $paidCount,
            'unpaid_count' => (int) $row->unpaid_count,
            'last_30_days' => (int) $row->last_30_days,
            'last_30_revenue' => (float) $row->last_30_revenue,
            'avg_basket' => $paidCount > 0 ? round($totalRevenue / $paidCount, 2) : 0,
            'synced_at' => $this->lastSyncAt($request),
        ]);
    }

    /** Ticimax'tan son başarılı yeni-sipariş taraması (panelde "x dk önce güncellendi"). */
    /** Sipariş kalemi eşleşmesi: ürün adı / stok kodu / barkod (trigram indeksli). */
    private function matchItem($q, string $product)
    {
        return $q->where('product_name', 'ilike', "%{$product}%")
            ->orWhere('sku', 'ilike', "%{$product}%")
            ->orWhere('barcode', 'ilike', "%{$product}%");
    }

    /**
     * Markası aramaya uyan ürün kartları, tenant_id => [product_id] (ör. "Slazenger" —
     * marka ürün adında geçmiyor). Kalem sorgusuna OR ile eklemek trigram indekslerini
     * bozuyordu (44 ms → 1,5 sn); bu yüzden id'ler önce çekilip UNION koluyla eklenir.
     * Ana marka gibi binlerce kartlı eşleşme ("Tonny Black" ≈ tüm siparişler, ~10 sn) atlanır.
     */
    private function brandProductIds(string $product): array
    {
        $rows = Product::where('brand', 'ilike', "%{$product}%")->limit(1001)->get(['id', 'tenant_id']);

        return $rows->count() > 1000 ? [] : $rows
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->pluck('id')->all())
            ->all();
    }

    /** Kalem sorgusunu marka eşleşmesiyle sınırla — (tenant_id, product_id) indeksiyle. */
    private function matchBrand($q, array $brandIds)
    {
        return $q->where(function ($q) use ($brandIds) {
            foreach ($brandIds as $tenantId => $ids) {
                $q->orWhere(fn ($q) => $q->where('tenant_id', $tenantId)->whereIn('product_id', $ids));
            }
        });
    }

    private function lastSyncAt(Request $request): ?string
    {
        $tenantId = (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id'));
        $settings = \App\Models\Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('type', 'like', '%-ecommerce')
            ->pluck('settings');
        $times = $settings->map(fn ($st) => $st['last_new_sync_at'] ?? null)->filter()->sort()->values();

        return $times->last();
    }

    public function statuses(): JsonResponse
    {
        $statuses = Order::select('status', 'status_label')
            ->selectRaw('count(*) as count')
            ->groupBy('status', 'status_label')
            ->orderByDesc('count')
            ->get();

        return response()->json($statuses);
    }

    public function sources(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id'));
        $sources = Cache::remember("crm:order-sources:{$tenantId}", 600, fn () => Order::select('order_source')
            ->selectRaw('count(*) as order_count')
            ->whereNotNull('order_source')
            ->where('order_source', '!=', '')
            ->groupBy('order_source')
            ->orderByDesc('order_count')
            ->get());

        return response()->json($sources);
    }

    private function applyFilters($query, Request $request, bool $withTab = true): void
    {
        $tab = $request->input('tab');
        if ($withTab && $tab && array_key_exists($tab, self::TABS)) {
            if ($tab === 'delayed') {
                $query->whereIn('id', $this->delayedIds($request) ?: [0]);
            } elseif (self::TABS[$tab] !== null) {
                $query->whereIn('status', self::TABS[$tab]);
            }
        }

        // Ürün adı / stok kodu / barkod / marka
        if ($request->filled('product')) {
            // whereIn alt sorgusu: trigram indeksleriyle önce eşleşen kalemler bulunur
            // (whereHas'ın EXISTS'i 1,4M kalemi taramaya düşüyordu, ~3,5 sn).
            $product = $this->escapeLike(trim($request->product));
            $brandIds = $this->brandProductIds($product);
            $query->whereIn('id', function ($q) use ($product, $brandIds) {
                $q->select('order_id')->from('order_items')->where(fn ($q) => $this->matchItem($q, $product));
                if ($brandIds) {
                    $q->union(DB::table('order_items')->select('order_id')->where(fn ($q) => $this->matchBrand($q, $brandIds)));
                }
            });
        }

        if ($request->filled('cargo_company')) {
            $query->where('cargo_company', $request->cargo_company);
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'ilike', "%{$search}%")
                  ->orWhere('customer_name', 'ilike', "%{$search}%")
                  ->orWhere('customer_email', 'ilike', "%{$search}%")
                  ->orWhere('cargo_tracking_no', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', (int) $request->payment_status);
        }

        if ($request->filled('sources')) {
            $sources = array_filter(array_map('trim', explode(',', $request->sources)));
            if (! empty($sources)) {
                $query->whereIn('order_source', $sources);
            }
        }

        // Ödeme yöntemi: cod (kapıda, iki tür) / cod_cash / cod_card / card / marketplace / transfer / other
        if ($request->filled('payment_methods')) {
            PaymentMethod::apply($query, PaymentMethod::expand((string) $request->payment_methods));
        }

        if ($request->filled('channel_id')) {
            $query->where('channel_id', (int) $request->channel_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }

        if ($request->filled('date_from')) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date_from)) {
                $query->where('order_date', '>=', $request->date_from . ' 00:00:00');
            }
        }

        if ($request->filled('date_to')) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date_to)) {
                $query->where('order_date', '<=', $request->date_to . ' 23:59:59');
            }
        }
    }
}
