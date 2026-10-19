<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform_order_id');
            $table->string('platform_customer_id')->nullable();
            $table->string('order_no')->nullable();
            $table->string('order_code')->nullable();
            $table->smallInteger('status')->default(0);
            $table->string('status_label')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('gift_card_amount', 12, 2)->default(0);
            $table->decimal('points_discount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 10)->default('TRY');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('cargo_company')->nullable();
            $table->string('cargo_tracking_no')->nullable();
            $table->text('cargo_tracking_url')->nullable();
            $table->smallInteger('payment_type')->nullable();
            $table->smallInteger('payment_status')->nullable();
            $table->string('order_source')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->smallInteger('items_count')->default(0);
            $table->timestamp('order_date')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('platform_updated_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel_id', 'platform_order_id']);
            $table->index('customer_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'order_date']);
            $table->index('order_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
