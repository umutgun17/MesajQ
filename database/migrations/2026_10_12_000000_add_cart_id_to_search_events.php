<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Akıllı Arama: IP tutmayan platformlarda (ikas) satış atfı için tıklamaya mağaza sepet kimliği eklenir (ikas: sepet id = sipariş id). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->string('cart_id', 64)->nullable()->after('session_id');
            $table->index(['tenant_id', 'cart_id']);
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'cart_id']);
            $table->dropColumn('cart_id');
        });
    }
};
