<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sepet içi "Sana Özel Teklif" kayıtları — müşteri süreli teklifi kullandığında
 * (CTA) satır açılır. mode=coupon ise Ticimax'ta gerçek hediye çeki üretilir ve
 * kodu burada tutulur; sipariş coupon_code eşleşmesiyle kesin atıf yapılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('platform_member_id', 32)->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('platform_product_id', 64)->nullable();
            $table->string('platform_variant_id', 64)->nullable();
            $table->string('mode', 16)->default('campaign'); // campaign | coupon
            $table->string('coupon_code', 64)->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable(); // Ticimax HediyeCekiId
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('product_price', 12, 2)->nullable();
            $table->decimal('cart_total', 12, 2)->nullable();
            $table->decimal('min_basket', 12, 2)->nullable();
            $table->string('status', 16)->default('claimed'); // claimed | issued | failed | converted
            $table->string('error', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('platform_order_id', 64)->nullable();
            $table->decimal('order_value', 12, 2)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'ro_tenant_created_idx');
            $table->index(['tenant_id', 'coupon_code'], 'ro_tenant_code_idx');
            $table->index(['tenant_id', 'session_id', 'product_id'], 'ro_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_offers');
    }
};
