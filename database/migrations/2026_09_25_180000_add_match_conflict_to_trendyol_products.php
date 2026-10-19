<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eslestirme stok kodu (MODEL_RENK_BEDEN) oncelikli hale getirildi; barkod capraz kontrol.
     * barcode_product_id: barkodun isaret ettigi site urunu (stok kodundan farkliysa match_conflict=true).
     */
    public function up(): void
    {
        Schema::table('trendyol_products', function (Blueprint $table) {
            $table->unsignedBigInteger('barcode_product_id')->nullable()->after('product_variant_id');
            $table->boolean('match_conflict')->default(false)->after('barcode_product_id');
            $table->index(['tenant_id', 'match_conflict']);
        });
    }

    public function down(): void
    {
        Schema::table('trendyol_products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'match_conflict']);
            $table->dropColumn(['barcode_product_id', 'match_conflict']);
        });
    }
};
