<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ikas platform ID'leri UUID string'tir; product_favorites'taki üç
     * platform kolonu INTEGER kalmış tek tabloydu (diğer tüm platform_*
     * kolonları zaten string). Mevcut Ticimax integer değerleri dönüşümde
     * korunur; unique zaten (tenant_id, platform_favorite_id) composite.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE product_favorites ALTER COLUMN platform_favorite_id TYPE varchar(64) USING platform_favorite_id::varchar');
        DB::statement('ALTER TABLE product_favorites ALTER COLUMN platform_customer_id TYPE varchar(64) USING platform_customer_id::varchar');
        DB::statement('ALTER TABLE product_favorites ALTER COLUMN platform_product_id TYPE varchar(64) USING platform_product_id::varchar');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_favorites ALTER COLUMN platform_favorite_id TYPE integer USING platform_favorite_id::integer');
        DB::statement('ALTER TABLE product_favorites ALTER COLUMN platform_customer_id TYPE integer USING platform_customer_id::integer');
        DB::statement('ALTER TABLE product_favorites ALTER COLUMN platform_product_id TYPE integer USING platform_product_id::integer');
    }
};
