<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_source', 'orders_order_source_idx');
            $table->index(['tenant_id', 'payment_status'], 'orders_tenant_payment_status_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['sender_type', 'created_at'], 'messages_sender_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_order_source_idx');
            $table->dropIndex('orders_tenant_payment_status_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_sender_type_created_idx');
        });
    }
};
