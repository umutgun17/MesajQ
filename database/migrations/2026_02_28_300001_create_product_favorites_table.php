<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->integer('platform_favorite_id')->unique();
            $table->integer('platform_customer_id')->index();
            $table->integer('platform_product_id')->index();
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->string('product_image')->nullable();
            $table->string('product_url')->nullable();
            $table->decimal('product_price', 12, 4)->default(0);
            $table->decimal('product_price_tax', 12, 4)->default(0);
            $table->string('currency', 5)->default('TL');
            $table->decimal('stock_quantity', 10, 2)->default(0);
            $table->integer('variant_count')->default(0);
            $table->timestamp('favorited_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'favorited_at']);
            $table->index(['tenant_id', 'platform_product_id']);
            $table->index(['tenant_id', 'platform_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_favorites');
    }
};
