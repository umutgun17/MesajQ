<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pazarlama attribution'ı siparişi müşteri E-POSTASIYLA da eşler (çark SMS → kuponsuz
 * sipariş). customer_email üzerinde indeks yoktu → her eşleme 300k+ satırlık seq scan
 * yapıyordu. Aramalar LOWER() ile yapıldığı için expression index gerekir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS orders_tenant_lower_email_idx ON orders (tenant_id, LOWER(customer_email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_tenant_lower_email_idx');
    }
};
