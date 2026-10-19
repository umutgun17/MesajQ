<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('platform_product_id');
            $table->string('name')->default('');
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->string('category_path')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('discounted_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->string('currency', 10)->default('TRY');
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->jsonb('images')->default('[]');
            $table->text('url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('platform_created_at')->nullable();
            $table->timestamp('platform_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel_id', 'platform_product_id'], 'products_tenant_channel_platform_unique');
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'brand']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
