<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            // Composite index for tenant-scoped product queries (most common widget query)
            $table->index(['tenant_id', 'product_id'], 'product_reviews_tenant_product_idx');

            // Composite index for star filter queries
            $table->index(['product_id', 'rate'], 'product_reviews_product_rate_idx');

            // Composite index for sorted pagination
            $table->index(['product_id', 'reviewed_at'], 'product_reviews_product_reviewed_idx');

            // Composite index for like_count sorting
            $table->index(['product_id', 'like_count'], 'product_reviews_product_likes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropIndex('product_reviews_tenant_product_idx');
            $table->dropIndex('product_reviews_product_rate_idx');
            $table->dropIndex('product_reviews_product_reviewed_idx');
            $table->dropIndex('product_reviews_product_likes_idx');
        });
    }
};
