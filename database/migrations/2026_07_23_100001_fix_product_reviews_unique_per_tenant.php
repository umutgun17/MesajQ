<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canlı DB'de platform_review_id üzerinde GLOBAL unique index var (migration
     * dosyasındaki product_id+platform_review_id tanımından sapmış). İki tenant
     * aynı Trendyol ürününü satarsa aynı platform_review_id iki tenant'ta da
     * meşru — unique tenant kapsamına çekildi. IF EXISTS ile drift'e dayanıklı.
     */
    public function up(): void
    {
        // Canlıda constraint olarak tanımlı (DROP INDEX reddediyor); her iki
        // ihtimale karşı önce constraint sonra çıplak index düşürülür.
        DB::statement('ALTER TABLE product_reviews DROP CONSTRAINT IF EXISTS product_reviews_platform_review_id_unique');
        DB::statement('DROP INDEX IF EXISTS product_reviews_platform_review_id_unique');

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->unique(['tenant_id', 'platform_review_id'], 'product_reviews_tenant_platform_review_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropUnique('product_reviews_tenant_platform_review_unique');
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS product_reviews_platform_review_id_unique ON product_reviews (platform_review_id)');
    }
};
