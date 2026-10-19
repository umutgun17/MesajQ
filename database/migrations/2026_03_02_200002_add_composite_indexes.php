<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->index(['tenant_id', 'platform_variant_id'], 'pv_tenant_platform_idx');
            $table->index(['tenant_id', 'sku'], 'pv_tenant_sku_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['tenant_id', 'order_id'], 'oi_tenant_order_idx');
            $table->index(['tenant_id', 'product_id'], 'oi_tenant_product_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'msg_tenant_conv_created_idx');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->index(['tenant_id', 'conversation_id', 'status'], 'cmt_tenant_conv_status_idx');
        });

        Schema::table('comment_replies', function (Blueprint $table) {
            $table->index(['tenant_id', 'comment_id'], 'cr_tenant_comment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('pv_tenant_platform_idx');
            $table->dropIndex('pv_tenant_sku_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('oi_tenant_order_idx');
            $table->dropIndex('oi_tenant_product_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('msg_tenant_conv_created_idx');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('cmt_tenant_conv_status_idx');
        });

        Schema::table('comment_replies', function (Blueprint $table) {
            $table->dropIndex('cr_tenant_comment_idx');
        });
    }
};
