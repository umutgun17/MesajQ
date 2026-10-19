<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_ga4_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('list_views')->default(0);
            $table->unsignedInteger('list_clicks')->default(0);
            $table->unsignedInteger('add_to_carts')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->unsignedSmallInteger('period_days')->default(90);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'period_days']);
            $table->index(['tenant_id', 'synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_ga4_cache');
    }
};
