<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sipariş sync'i müşteriyi (tenant, platform_customer_id) ile arıyor; mevcut benzersiz
 * indeks (tenant, channel, platform_customer_id) kanal olmadan ~30 ms/sipariş tarıyordu.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS customers_tenant_platform_idx ON customers (tenant_id, platform_customer_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_tenant_platform_idx');
    }
};
