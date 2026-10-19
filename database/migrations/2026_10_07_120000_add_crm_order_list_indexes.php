<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM › Siparişler hızlandırma (7 Eki 2026):
 *  - liste/istatistik/sekme sayıları: (tenant, ödeme, tarih) + kapsayan sütunlar → heap'e gitmeden sayım
 *  - ürün adı/stok kodu/barkod ve sipariş no/alıcı/e-posta/takip no "içinde geçen" aramaları: trigram
 * Canlı tabloda kilitlememek için CONCURRENTLY (transaction dışında).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_crm_list_idx ON orders (tenant_id, payment_status, order_date) INCLUDE (order_source, status, total_amount, channel_id, cargo_company)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_name_trgm ON order_items USING gin (product_name gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_sku_trgm ON order_items USING gin (sku gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS order_items_barcode_trgm ON order_items USING gin (barcode gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_order_no_trgm ON orders USING gin (order_no gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_customer_name_trgm ON orders USING gin (customer_name gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_customer_email_trgm ON orders USING gin (customer_email gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_cargo_tracking_trgm ON orders USING gin (cargo_tracking_no gin_trgm_ops)');
    }

    public function down(): void
    {
        foreach (['orders_crm_list_idx', 'order_items_name_trgm', 'order_items_sku_trgm', 'order_items_barcode_trgm',
            'orders_order_no_trgm', 'orders_customer_name_trgm', 'orders_customer_email_trgm', 'orders_cargo_tracking_trgm'] as $idx) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$idx}");
        }
    }
};
