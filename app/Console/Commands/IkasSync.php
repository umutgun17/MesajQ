<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\IkasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ikas CRM senkronu — müşteriler + siparişler. Ürünler crm:sync-products
 * üzerinden gelir (IkasEcommerceAdapter); bu komut Ticimax'ın
 * SyncCrmMembers/SyncCrmOrders muadilidir ve AYNI tablo/kolon
 * sözleşmesiyle yazar (Ticimax koduna dokunulmaz).
 *
 * Artımlı: updatedAt.gte (epoch ms) cursor'ları kanal ayarlarında
 * members_synced_at / last_order_sync_at olarak tutulur (Ticimax ile aynı
 * anahtar adları). --full cursor'ları yok sayar.
 */
class IkasSync extends Command
{
    protected $signature = 'ikas:sync
        {--what=all : customers|orders|products|carts|all}
        {--channel= : Belirli kanal ID}
        {--full : Cursor yok say, tüm geçmişi çek}
        {--limit=200 : Sayfa başı kayıt (max 200)}';

    protected $description = 'ikas mağaza verilerini senkronize eder (müşteri + sipariş; ürünler crm:sync-products ile)';

    protected int $customersNew = 0;
    protected int $customersUpdated = 0;
    protected int $ordersNew = 0;
    protected int $ordersUpdated = 0;

    public function handle(): int
    {
        $query = Channel::withoutGlobalScopes()
            ->where('type', 'ikas-ecommerce')
            ->where('is_active', true)
            ->orderBy('id');

        if ($channelId = $this->option('channel')) {
            $query->where('id', (int) $channelId);
        }

        $channels = $query->get();
        if ($channels->isEmpty()) {
            $this->warn('Aktif ikas-ecommerce kanalı yok.');

            return self::SUCCESS;
        }

        $what = (string) $this->option('what');

        foreach ($channels as $channel) {
            $service = IkasService::fromChannel($channel);
            if (! $service->isAvailable()) {
                $this->warn("Kanal #{$channel->id} (tenant #{$channel->tenant_id}): ikas kimlikleri eksik, atlandı.");
                continue;
            }

            $this->info("Kanal #{$channel->id} (tenant #{$channel->tenant_id}) işleniyor — {$what}");

            if (in_array($what, ['products', 'all'], true)) {
                $this->call('crm:sync-products', array_filter([
                    '--channel' => (string) $channel->id,
                    '--full' => (bool) $this->option('full'),
                ]));
            }

            if (in_array($what, ['customers', 'all'], true)) {
                $this->syncCustomers($service, $channel);
            }

            if (in_array($what, ['orders', 'all'], true)) {
                $this->syncOrders($service, $channel);
                $this->recalculateCustomerTotals($channel);
            }

            if (in_array($what, ['carts', 'all'], true)) {
                $this->syncCarts($service, $channel);
            }
        }

        $this->info("Bitti: müşteri {$this->customersNew} yeni/{$this->customersUpdated} güncel; sipariş {$this->ordersNew} yeni/{$this->ordersUpdated} güncel");
        Log::info('ikas:sync tamamlandı', [
            'customers_new' => $this->customersNew,
            'customers_updated' => $this->customersUpdated,
            'orders_new' => $this->ordersNew,
            'orders_updated' => $this->ordersUpdated,
        ]);

        return self::SUCCESS;
    }

    // ── Müşteriler ─────────────────────────────────────────────

    protected function syncCustomers(IkasService $service, Channel $channel): void
    {
        $cursorMs = $this->cursorMs($channel, 'members_synced_at');
        $limit = min(200, max(1, (int) $this->option('limit')));
        $page = 1;
        $processed = 0;

        do {
            $r = $service->listCustomers($cursorMs, $page, $limit);

            foreach ($r['data'] as $c) {
                $this->upsertCustomer($c, $channel);
                $processed++;
            }

            $this->output->write("\r  müşteriler: {$processed}" . ($r['count'] ? "/{$r['count']}" : ''));
            $page++;
        } while ($r['hasNext']);

        $this->newLine();
        $this->saveCursor($channel, 'members_synced_at');
    }

    protected function upsertCustomer(array $c, Channel $channel): void
    {
        $platformId = (string) ($c['id'] ?? '');
        if ($platformId === '') {
            return;
        }

        $mapped = [
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'platform_type' => 'ikas',
            'name' => trim((string) ($c['firstName'] ?? '')) ?: null,
            'surname' => trim((string) ($c['lastName'] ?? '')) ?: null,
            'email' => ! empty($c['email']) ? mb_strtolower(trim($c['email'])) : null,
            'phone' => $this->normalizePhone($c['phone'] ?? null),
            'birth_date' => IkasService::msToDate($c['birthDate'] ?? null),
            'is_active' => ($c['accountStatus'] ?? null) !== 'DISABLED' && empty($c['deleted']),
            'mail_consent' => in_array(($c['subscriptionStatus'] ?? ''), ['SUBSCRIBED', 'SUBSCRIBED_ALL'], true),
            'platform_created_at' => IkasService::msToDate($c['createdAt'] ?? null),
        ];

        $existing = Customer::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('platform_customer_id', $platformId)
            ->first();

        if ($existing) {
            $existing->update($mapped);
            $this->customersUpdated++;
        } else {
            $mapped['platform_customer_id'] = $platformId;
            Customer::withoutGlobalScopes()->create($mapped);
            $this->customersNew++;
        }
    }

    // ── Siparişler ─────────────────────────────────────────────

    protected function syncOrders(IkasService $service, Channel $channel): void
    {
        $cursorMs = $this->cursorMs($channel, 'last_order_sync_at');
        $limit = min(200, max(1, (int) $this->option('limit')));
        $page = 1;
        $processed = 0;

        do {
            $r = $service->listOrders($cursorMs, $page, $limit);

            foreach ($r['data'] as $o) {
                try {
                    $this->upsertOrder($o, $channel);
                } catch (\Throwable $e) {
                    Log::warning('ikas:sync sipariş yazılamadı', [
                        'order_id' => $o['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
                $processed++;
            }

            $this->output->write("\r  siparişler: {$processed}" . ($r['count'] ? "/{$r['count']}" : ''));
            $page++;
        } while ($r['hasNext']);

        $this->newLine();
        $this->saveCursor($channel, 'last_order_sync_at');
    }

    protected function upsertOrder(array $o, Channel $channel): void
    {
        $platformId = (string) ($o['id'] ?? '');
        if ($platformId === '') {
            return;
        }

        [$status, $statusLabel] = $this->mapStatus($o);
        $customer = $o['customer'] ?? [];
        $platformCustomerId = (string) ($o['customerId'] ?? ($customer['id'] ?? ''));

        $customerId = $platformCustomerId !== ''
            ? Customer::withoutGlobalScopes()
                ->where('tenant_id', $channel->tenant_id)
                ->where('platform_customer_id', $platformCustomerId)
                ->value('id')
            : null;

        $mapped = [
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'platform_customer_id' => $platformCustomerId ?: null,
            'customer_id' => $customerId,
            'order_no' => (string) ($o['orderNumber'] ?? ''),
            'status' => $status,
            'status_label' => $statusLabel,
            'subtotal' => (float) ($o['totalPrice'] ?? 0),
            'coupon_code' => $o['couponCode'] ?? null,
            'total_amount' => (float) ($o['totalFinalPrice'] ?? 0),
            'paid_amount' => in_array(($o['orderPaymentStatus'] ?? ''), ['PAID'], true) ? (float) ($o['totalFinalPrice'] ?? 0) : 0,
            'currency' => $o['currencyCode'] ?? 'TRY',
            'customer_name' => trim((string) ($customer['fullName'] ?? trim(($customer['firstName'] ?? '') . ' ' . ($customer['lastName'] ?? '')))) ?: null,
            'customer_email' => ! empty($customer['email']) ? mb_strtolower(trim($customer['email'])) : null,
            'payment_status' => in_array(($o['orderPaymentStatus'] ?? ''), ['PAID', 'PARTIALLY_PAID'], true) ? 1 : 0,
            'order_source' => 'ikas',
            'billing_address' => $this->mapAddress($o['billingAddress'] ?? null),
            'shipping_address' => $this->mapAddress($o['shippingAddress'] ?? null),
            'order_date' => IkasService::msToDate($o['orderedAt'] ?? $o['createdAt'] ?? null),
            'platform_updated_at' => IkasService::msToDate($o['updatedAt'] ?? null),
        ];

        $existing = Order::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('platform_order_id', $platformId)
            ->first();

        if ($existing) {
            $existing->update($mapped);
            $order = $existing;
            $this->ordersUpdated++;
        } else {
            $mapped['platform_order_id'] = $platformId;
            $order = Order::withoutGlobalScopes()->create($mapped);
            $this->ordersNew++;
        }

        $this->syncOrderItems($order, $o['orderLineItems'] ?? [], $channel);
    }

    protected function syncOrderItems(Order $order, array $lines, Channel $channel): void
    {
        OrderItem::where('order_id', $order->id)->delete();

        $count = 0;
        foreach ($lines as $line) {
            $variant = $line['variant'] ?? [];
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $unit = (float) ($line['finalUnitPrice'] ?? $line['unitPrice'] ?? 0);
            $platformProductId = (string) ($variant['productId'] ?? '');

            $productId = $platformProductId !== ''
                ? Product::withoutGlobalScopes()
                    ->where('channel_id', $channel->id)
                    ->where('platform_product_id', $platformProductId)
                    ->value('id')
                : null;

            $variantId = null;
            if ($productId && ! empty($variant['id'])) {
                $variantId = ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $productId)
                    ->where('platform_variant_id', (string) $variant['id'])
                    ->value('id');
            }

            OrderItem::create([
                'tenant_id' => $channel->tenant_id,
                'order_id' => $order->id,
                'platform_product_id' => $platformProductId ?: null,
                'platform_variant_id' => ! empty($variant['id']) ? (string) $variant['id'] : null,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'product_name' => $variant['name'] ?? 'Ürün',
                'sku' => $variant['sku'] ?? null,
                'barcode' => ($variant['barcodeList'] ?? [])[0] ?? null,
                'quantity' => $qty,
                'unit_price' => $unit,
                'total_price' => (float) ($line['finalPrice'] ?? $unit * $qty),
                'discount_amount' => isset($line['discountPrice']) ? (float) $line['discountPrice'] : 0,
                'tax_amount' => isset($line['taxValue']) ? (float) $line['taxValue'] : 0,
                'status' => $this->itemStatusCode((string) ($line['status'] ?? '')),
                'status_label' => $this->itemStatusLabel((string) ($line['status'] ?? '')),
            ]);
            $count++;
        }

        if ($count > 0) {
            $order->updateQuietly(['items_count' => $count]);
        }
    }

    // ── Terk edilmiş sepetler ──────────────────────────────────
    // Ticimax kuralıyla aynı temsile yazılır: orders satırı, status=0 +
    // payment_status=0 (sepet-kurtarma motoru bu kesiti tarar). Gerçek
    // siparişle çakışmasın diye platform_order_id 'cart:{id}' öneklidir;
    // sepet siparişe dönüşünce/ölünce yerel satır silinir (gerçek sipariş
    // kendi kimliğiyle zaten ayrıca gelir).

    protected function syncCarts(IkasService $service, Channel $channel): void
    {
        $cursorMs = $this->cursorMs($channel, 'carts_synced_at');
        $limit = min(200, max(1, (int) $this->option('limit')));
        $page = 1;
        $upserted = 0;
        $removed = 0;

        do {
            $r = $service->listAbandonedCarts($cursorMs, $page, $limit);

            foreach ($r['data'] as $c) {
                $id = (string) ($c['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $stillAbandoned = ($c['status'] ?? '') === 'OPEN'
                    && ($c['recoveryStatus'] ?? '') !== 'RECOVERED'
                    && empty($c['orderId']);

                if (! $stillAbandoned) {
                    $removed += Order::withoutGlobalScopes()
                        ->where('tenant_id', $channel->tenant_id)
                        ->where('channel_id', $channel->id)
                        ->where('platform_order_id', "cart:{$id}")
                        ->delete();
                    continue;
                }

                $customer = $c['customer'] ?? [];
                $platformCustomerId = (string) ($customer['id'] ?? '');

                $order = Order::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $channel->tenant_id,
                        'channel_id' => $channel->id,
                        'platform_order_id' => "cart:{$id}",
                    ],
                    [
                        'platform_customer_id' => $platformCustomerId ?: null,
                        'customer_id' => $platformCustomerId !== ''
                            ? Customer::withoutGlobalScopes()
                                ->where('tenant_id', $channel->tenant_id)
                                ->where('platform_customer_id', $platformCustomerId)
                                ->value('id')
                            : null,
                        'order_no' => 'SEPET',
                        'status' => 0,
                        'status_label' => 'Sepet (Terk)',
                        'payment_status' => 0,
                        'subtotal' => (float) ($c['totalFinalPrice'] ?? 0),
                        'total_amount' => (float) ($c['totalFinalPrice'] ?? 0),
                        'currency' => 'TRY',
                        'customer_name' => trim((string) ($customer['fullName'] ?? trim(($customer['firstName'] ?? '') . ' ' . ($customer['lastName'] ?? '')))) ?: null,
                        'customer_email' => ! empty($customer['email']) ? mb_strtolower(trim($customer['email'])) : null,
                        'order_source' => 'ikas',
                        'shipping_address' => $this->mapAddress($c['shippingAddress'] ?? null),
                        'order_date' => IkasService::msToDate($c['createdAt'] ?? null),
                        'platform_updated_at' => IkasService::msToDate($c['updatedAt'] ?? null),
                    ]
                );
                $this->syncOrderItems($order, $c['cart']['items'] ?? [], $channel);
                $upserted++;
            }

            $this->output->write("\r  sepetler: sayfa {$page}");
            $page++;
        } while ($r['hasNext']);

        $this->newLine();
        $this->info("  sepet: {$upserted} güncel/aktif, {$removed} kapanan silindi");
        $this->saveCursor($channel, 'carts_synced_at');
    }

    /**
     * ikas durumları → panelin bildiği Ticimax 'Durum' sözlüğü
     * (config/returns.php: 0=alındı, 5=hazırlık, 6=kargoda, 7=teslim,
     * 8=iptal, 11=iade talep, 12=kısmi iade, 13=iade ödemesi yapıldı).
     */
    protected function mapStatus(array $o): array
    {
        $status = (string) ($o['status'] ?? '');
        $package = (string) ($o['orderPackageStatus'] ?? '');

        return match (true) {
            $status === 'CANCELLED' => [8, 'İptal Edildi'],
            $status === 'REFUNDED' => [13, 'İade Ödemesi Yapıldı'],
            $status === 'PARTIALLY_REFUNDED' => [12, 'Kısmi İade'],
            $status === 'REFUND_REQUESTED' => [11, 'İade Talep Alındı'],
            str_contains($package, 'DELIVERED') => [7, 'Teslim Edildi'],
            str_contains($package, 'FULFILLED') && $package !== 'UNFULFILLED' => [6, 'Kargoya Verildi'],
            str_contains($package, 'READY') || str_contains($package, 'PREPAR') => [5, 'Hazırlanıyor'],
            // Ödemesi bekleyen (havale/EFT) gerçek sipariş 'Onay Bekliyor' —
            // status 0 + ödenmemiş kombinasyonu sepet-terk kuralıyla karışmasın.
            ! in_array((string) ($o['orderPaymentStatus'] ?? ''), ['PAID', 'PARTIALLY_PAID'], true) => [3, 'Onay Bekliyor (Ödeme)'],
            default => [0, 'Sipariş Alındı'],
        };
    }

    /** Kalem durumu → sayısal Durum sözlüğü (order_items.status NOT NULL). */
    protected function itemStatusCode(string $s): int
    {
        return match (true) {
            str_contains($s, 'CANCEL') => 8,
            str_contains($s, 'REFUND') => 13,
            str_contains($s, 'DELIVERED') => 7,
            str_contains($s, 'FULFILLED') && ! str_contains($s, 'UNFULFILLED') => 6,
            default => 0,
        };
    }

    protected function itemStatusLabel(string $s): ?string
    {
        return match (true) {
            $s === '' => null,
            str_contains($s, 'CANCEL') => 'İptal',
            str_contains($s, 'REFUND') => 'İade',
            str_contains($s, 'DELIVERED') => 'Teslim Edildi',
            str_contains($s, 'FULFILLED') => 'Kargoya Verildi',
            default => $s,
        };
    }

    protected function mapAddress(?array $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'name' => trim(($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? '')) ?: ($a['company'] ?? null),
            'phone' => $a['phone'] ?? null,
            'city' => $a['city']['name'] ?? null,
            'district' => $a['district']['name'] ?? null,
            'neighborhood' => null,
            'address' => trim(($a['addressLine1'] ?? '') . ' ' . ($a['addressLine2'] ?? '')) ?: null,
            'postal_code' => $a['postalCode'] ?? null,
            'country' => $a['country']['name'] ?? null,
            'tax_office' => $a['taxOffice'] ?? null,
            'tax_no' => $a['taxNumber'] ?? null,
        ];
    }

    /** Ticimax SyncCrmOrders::recalculateCustomerTotals muadili (kanal kapsamlı). */
    protected function recalculateCustomerTotals(Channel $channel): void
    {
        DB::statement('
            UPDATE customers c SET
                total_orders = s.cnt,
                total_spent = s.total
            FROM (
                SELECT customer_id, COUNT(*) cnt, COALESCE(SUM(total_amount),0) total
                FROM orders
                WHERE channel_id = ? AND customer_id IS NOT NULL AND payment_status = 1
                GROUP BY customer_id
            ) s
            WHERE c.id = s.customer_id
              AND (c.total_orders IS DISTINCT FROM s.cnt OR c.total_spent IS DISTINCT FROM s.total)
        ', [$channel->id]);
    }

    protected function cursorMs(Channel $channel, string $key): ?int
    {
        if ($this->option('full')) {
            return null;
        }

        $raw = $channel->settings[$key] ?? null;
        if (! $raw) {
            return null;
        }

        try {
            // 30 dk örtüşme payı: updatedAt eşitlik sınırında kayıt kaçmasın
            return (\Carbon\Carbon::parse($raw)->getTimestampMs()) - 30 * 60 * 1000;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function saveCursor(Channel $channel, string $key): void
    {
        $settings = $channel->fresh()->settings ?? [];
        $settings[$key] = now()->toIso8601String();
        $channel->update(['settings' => $settings]);
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);

        return $digits !== '' ? $digits : null;
    }
}
