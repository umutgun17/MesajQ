<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kreatif Stüdyo v2: dinamik ürün setleri (kural bazlı, feed senkronunda
// otomatik güncellenir) + enhanced katalog için supplementary feed token'ı.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sets', function (Blueprint $table) {
            // {type:'manual'} | {type:'filter', rules:{q,category,brand,custom_label,only_discounted,only_in_stock}}
            $table->jsonb('source')->default('{"type":"manual"}');
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::table('enhanced_catalogs', function (Blueprint $table) {
            $table->string('feed_token', 40)->nullable();
        });

        // Mevcut kayıtlara token üret
        foreach (DB::table('enhanced_catalogs')->whereNull('feed_token')->pluck('id') as $id) {
            DB::table('enhanced_catalogs')->where('id', $id)->update(['feed_token' => bin2hex(random_bytes(16))]);
        }
    }

    public function down(): void
    {
        Schema::table('product_sets', function (Blueprint $table) {
            $table->dropColumn(['source', 'last_synced_at']);
        });
        Schema::table('enhanced_catalogs', function (Blueprint $table) {
            $table->dropColumn('feed_token');
        });
    }
};
