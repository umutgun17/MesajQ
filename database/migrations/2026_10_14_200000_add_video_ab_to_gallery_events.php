<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ürün videosu A/B: olayın kovası (A = video gösterildi, B = gizlendi) + satış atfı için IP (yalnız video_expose/add_cart). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_events', function (Blueprint $table) {
            $table->string('ab', 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('gallery_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'created_at']);
            $table->dropColumn(['ab', 'ip_address']);
        });
    }
};
