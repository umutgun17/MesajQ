<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VapiService
{
    protected ?int $resolvedTenantId = null;

    public function validateServerSecret(string $token): bool
    {
        $secret = config('services.vapi.server_secret');

        return $secret && hash_equals($secret, $token);
    }

    /**
     * Gelen Vapi isteğindeki assistantId'yi tenant ayarlarıyla
     * (settings['vapi']['assistant_id']) eşleştirir. Eşleşme yoksa
     * env VAPI_TENANT_ID'ye düşülür (geriye uyumluluk).
     */
    public function resolveTenantFromRequest(Request $request): void
    {
        $message = (array) $request->input('message', []);
        $assistantId = data_get($message, 'call.assistantId')
            ?? data_get($message, 'assistant.id')
            ?? data_get($message, 'assistantId')
            ?? $request->input('call.assistantId');

        if (! $assistantId) {
            return;
        }

        $tenant = Tenant::where('is_active', true)
            ->get()
            ->first(fn (Tenant $t) => (($t->settings ?? [])['vapi']['assistant_id'] ?? null) === $assistantId);

        if ($tenant) {
            $this->resolvedTenantId = $tenant->id;

            return;
        }

        Log::warning('VAPI: assistantId hiçbir tenant ayarıyla eşleşmedi, env varsayılanı kullanılacak', [
            'assistant_id' => $assistantId,
        ]);
    }

    public function getTenantId(): int
    {
        return $this->resolvedTenantId ?? (int) config('services.vapi.tenant_id', 1);
    }

    /**
     * Telefon numarasından müşteri bul + son siparişleri getir.
     */
    public function lookupCustomerByPhone(string $phone): ?array
    {
        $tenantId = $this->getTenantId();
        $normalized = Call::normalizePhone($phone);

        if (strlen($normalized) < 10) {
            Log::info('VAPI customer-lookup: invalid phone', ['phone' => $phone]);
            return null;
        }

        // 1. customers tablosunda ara
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '\\D', '', 'g'), 10) = ?", [$normalized])
            ->first();

        // 2. Bulunamadıysa shipping_address->phone ile ara
        if (! $customer) {
            $order = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereRaw("RIGHT(REGEXP_REPLACE(shipping_address->>'phone', '\\D', '', 'g'), 10) = ?", [$normalized])
                ->orderByDesc('order_date')
                ->first();

            if ($order && $order->customer_id) {
                $customer = Customer::withoutGlobalScopes()->find($order->customer_id);
            }
        }

        // 3. billing_address->phone ile de dene
        if (! $customer) {
            $order = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereRaw("RIGHT(REGEXP_REPLACE(billing_address->>'phone', '\\D', '', 'g'), 10) = ?", [$normalized])
                ->orderByDesc('order_date')
                ->first();

            if ($order && $order->customer_id) {
                $customer = Customer::withoutGlobalScopes()->find($order->customer_id);
            }
        }

        if (! $customer) {
            Log::info('VAPI customer-lookup: not found', ['phone' => $phone]);
            return null;
        }

        // Son siparişleri getir
        $recentOrders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->orderByDesc('order_date')
            ->limit(5)
            ->get(['id', 'order_no', 'status_label', 'total_amount', 'cargo_company', 'cargo_tracking_no', 'order_date', 'items_count']);

        // İstatistikler
        $stats = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->selectRaw('count(*) as total_orders, coalesce(sum(total_amount), 0) as total_spent')
            ->first();

        // Aktif iade/şikayet durumu
        $activeIssues = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->whereIn('status', [11, 12, 13]) // iade talebi, iade ulaştı, iade ödemesi
            ->count();

        Log::info('VAPI customer-lookup: found', ['phone' => $phone, 'customer_id' => $customer->id]);

        return [
            'found' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => trim(($customer->name ?? '') . ' ' . ($customer->surname ?? '')),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'city' => $customer->city,
                'member_type' => $customer->member_type,
            ],
            'stats' => [
                'total_orders' => (int) $stats->total_orders,
                'total_spent' => round((float) $stats->total_spent, 2),
                'active_returns' => $activeIssues,
            ],
            'recent_orders' => $recentOrders->map(fn ($o) => [
                'order_no' => $o->order_no,
                'date' => $o->order_date?->format('d.m.Y'),
                'status' => $o->status_label,
                'total' => round((float) $o->total_amount, 2),
                'items_count' => $o->items_count,
                'cargo_company' => $o->cargo_company,
                'cargo_tracking_no' => $o->cargo_tracking_no,
            ])->toArray(),
        ];
    }

    /**
     * Sipariş numarasıyla sipariş detayı getir.
     */
    public function lookupOrder(string $orderNo): ?array
    {
        $tenantId = $this->getTenantId();

        // Sayısal giriş — TB prefix'li de dene
        $cleanNo = trim($orderNo);

        $order = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($cleanNo) {
                $q->where('order_no', $cleanNo)
                  ->orWhere('order_no', 'TB' . $cleanNo)
                  ->orWhere('platform_order_id', $cleanNo);

                // Sadece sayısal girişlerde ID ile de dene
                if (ctype_digit($cleanNo)) {
                    $q->orWhere('id', (int) $cleanNo);
                }
            })
            ->with('items:id,order_id,product_name,variant_info,quantity,unit_price,total_price,status_label')
            ->first();

        if (! $order) {
            Log::info('VAPI order-lookup: not found', ['order_no' => $orderNo]);
            return null;
        }

        // Kargo durumu
        $cargoStatus = null;
        if ($order->cargo_tracking_no) {
            $cargoStatus = 'Takip numarası: ' . $order->cargo_tracking_no;
            if ($order->cargo_company) {
                $cargoStatus .= ' (' . $order->cargo_company . ')';
            }
        }

        // Ödeme tipi açıklaması
        $paymentLabel = match ($order->payment_type) {
            1, '1' => 'Kredi Kartı',
            2, '2' => 'Havale/EFT',
            3, '3' => 'Kapıda Ödeme',
            default => 'Kredi Kartı',
        };

        Log::info('VAPI order-lookup: found', ['order_no' => $orderNo, 'order_id' => $order->id]);

        return [
            'found' => true,
            'order' => [
                'order_no' => $order->order_no,
                'date' => $order->order_date?->format('d.m.Y H:i'),
                'status' => $order->status_label,
                'customer_name' => $order->customer_name,
                'total_amount' => round((float) $order->total_amount, 2),
                'payment_type' => $paymentLabel,
                'cargo_company' => $order->cargo_company,
                'cargo_tracking_no' => $order->cargo_tracking_no,
                'cargo_status' => $cargoStatus,
                'shipping_city' => $order->shipping_address['city'] ?? null,
                'items_count' => $order->items->count(),
                'items' => $order->items->map(fn ($i) => [
                    'product' => $i->product_name,
                    'variant' => $i->variant_info,
                    'quantity' => $i->quantity,
                    'price' => round((float) $i->unit_price, 2),
                    'total' => round((float) $i->total_price, 2),
                    'status' => $i->status_label,
                ])->toArray(),
            ],
        ];
    }

    /**
     * Ürün arama (isim, barkod veya SKU).
     * Sesli arama için Türkçe karakter toleranslı.
     */
    public function searchProducts(string $query = '', string $barcode = ''): array
    {
        $tenantId = $this->getTenantId();

        $q = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        if ($barcode) {
            $cleanBarcode = trim($barcode);

            // Barkod ile direkt varyant ara
            $variant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('barcode', $cleanBarcode)
                ->first();

            if ($variant) {
                $q->where('id', $variant->product_id);
            } else {
                // SKU'da da dene
                $product = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('sku', 'ilike', '%' . $this->escapeLike($cleanBarcode) . '%')
                    ->first();

                if ($product) {
                    $q->where('id', $product->id);
                } else {
                    return ['found' => false, 'message' => 'Bu barkod veya SKU ile eşleşen ürün bulunamadı.', 'products' => []];
                }
            }
        } elseif ($query) {
            $search = '%' . $this->escapeLike(trim($query)) . '%';

            // Stokta olan ürünleri önce göster
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'ilike', $search)
                   ->orWhere('sku', 'ilike', $search)
                   ->orWhere('category_path', 'ilike', $search)
                   ->orWhere('brand', 'ilike', $search);
            })
            ->orderByDesc('stock_quantity');
        } else {
            return ['found' => false, 'message' => 'Ürün adı veya barkod belirtilmedi.', 'products' => []];
        }

        $products = $q->with(['variants' => function ($vq) {
            $vq->select('id', 'product_id', 'sku', 'barcode', 'price', 'discounted_price', 'stock_quantity', 'attributes')
               ->where('stock_quantity', '>', 0)
               ->where('is_active', true)
               ->orderBy('sku');
        }])
            ->limit(5)
            ->get(['id', 'name', 'sku', 'price', 'discounted_price', 'brand', 'category_path', 'stock_quantity']);

        if ($products->isEmpty()) {
            Log::info('VAPI product-search: no results', ['query' => $query, 'barcode' => $barcode]);
            return ['found' => false, 'message' => 'Aramanızla eşleşen ürün bulunamadı.', 'products' => []];
        }

        Log::info('VAPI product-search: found', ['query' => $query, 'barcode' => $barcode, 'count' => $products->count()]);

        return [
            'found' => true,
            'count' => $products->count(),
            'products' => $products->map(fn ($p) => [
                'name' => $p->name,
                'brand' => $p->brand,
                'sku' => $p->sku,
                'price' => round((float) ($p->discounted_price ?: $p->price), 2),
                'original_price' => round((float) $p->price, 2),
                'discount' => $p->discounted_price && $p->discounted_price < $p->price
                    ? '%' . round((1 - $p->discounted_price / $p->price) * 100) . ' indirim'
                    : null,
                'category' => $p->category_path,
                'in_stock' => (int) $p->stock_quantity > 0,
                'total_stock' => (int) $p->stock_quantity,
                'available_sizes' => $p->variants->map(function ($v) {
                    $attrs = $v->attributes ?? [];
                    $size = $attrs['Beden'] ?? $attrs['beden'] ?? $attrs['Numara'] ?? $attrs['numara'] ?? null;

                    // Attributes'tan bulunamazsa SKU'dan çıkar (son segment genelde beden)
                    if (! $size && $v->sku) {
                        $parts = explode('|', $v->sku);
                        $size = end($parts);
                    }

                    return [
                        'size' => $size ?: '-',
                        'price' => round((float) ($v->discounted_price ?: $v->price), 2),
                        'stock' => (int) $v->stock_quantity,
                    ];
                })->toArray(),
            ])->toArray(),
        ];
    }

    /**
     * RAG sorgusu — eğitim verisi + bilgi bankasından bağlam döner.
     */
    public function queryRag(string $question): string
    {
        $tenantId = $this->getTenantId();
        $claudeService = app(ClaudeService::class);

        Log::info('VAPI rag-query', ['question' => $question]);

        return $claudeService->buildContextForVapi($question, $tenantId);
    }

    /**
     * VAPI call payload'ını Call modeli için map et.
     */
    public function createCallFromVapi(array $payload, int $channelId): Call
    {
        $tenantId = $this->getTenantId();
        $vapiCallId = $payload['call']['id'] ?? $payload['callId'] ?? null;
        $customerPhone = $payload['call']['customer']['number'] ?? $payload['customer']['number'] ?? null;

        $call = Call::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'common_id' => $vapiCallId],
            [
                'channel_id' => $channelId,
                'source' => 'vapi',
                'caller' => $customerPhone ?? 'unknown',
                'called' => $payload['call']['phoneNumber']['number'] ?? 'vapi',
                'direction' => 'inbound',
                'status' => 'answered',
                'started_at' => now(),
                'duration' => 0,
                'ring_duration' => 0,
            ]
        );

        // Telefon numarasından müşteri eşleştir
        if ($customerPhone) {
            $result = $this->lookupCustomerByPhone($customerPhone);
            if ($result && $result['found']) {
                $call->update(['customer_id' => $result['customer']['id']]);
            }
        }

        Log::info('VAPI call created', ['call_id' => $call->id, 'vapi_id' => $vapiCallId]);

        return $call;
    }

    /**
     * Çağrı bitişinde güncelle.
     */
    public function updateCallFromEndReport(array $payload): void
    {
        $tenantId = $this->getTenantId();
        $vapiCallId = $payload['call']['id'] ?? $payload['callId'] ?? null;

        if (! $vapiCallId) return;

        $call = Call::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('common_id', $vapiCallId)
            ->first();

        if (! $call) {
            Log::warning('VAPI end-of-call: call not found', ['vapi_id' => $vapiCallId]);
            return;
        }

        $endedReason = $payload['endedReason'] ?? 'unknown';

        // Transcript'i düz metin olarak kaydet
        $transcript = $payload['transcript'] ?? null;
        if (is_array($transcript)) {
            // VAPI structured transcript format: [{role, message, ...}]
            $transcript = collect($transcript)->map(function ($entry) {
                $role = ($entry['role'] ?? '') === 'assistant' ? 'AI' : 'Müşteri';
                return "{$role}: " . ($entry['message'] ?? $entry['content'] ?? '');
            })->implode("\n");
        }

        $call->update([
            'duration' => (int) ($payload['durationSeconds'] ?? $payload['call']['duration'] ?? 0),
            'status' => in_array($endedReason, ['assistant-error', 'pipeline-error-openai-llm-failed']) ? 'missed' : 'answered',
            'vapi_transcript' => $transcript,
            'ai_summary' => $payload['summary'] ?? null,
            'recording_url' => $payload['recordingUrl'] ?? $payload['call']['recordingUrl'] ?? null,
        ]);

        Log::info('VAPI call ended', [
            'call_id' => $call->id,
            'duration' => $call->duration,
            'reason' => $endedReason,
        ]);
    }

    /**
     * LIKE sorgusunda özel karakterleri escape et.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
