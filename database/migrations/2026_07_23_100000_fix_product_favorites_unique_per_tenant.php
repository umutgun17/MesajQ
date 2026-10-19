<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * platform_favorite_id Ticimax'ta mağaza-bazlı sayaç olduğundan global
     * unique iki tenant'ın aynı ID'ye sahip olmasını engelliyordu; unique
     * tenant kapsamına çekildi.
     */
    public function up(): void
    {
        Schema::table('product_favorites', function (Blueprint $table) {
            $table->dropUnique(['platform_favorite_id']);
            $table->unique(['tenant_id', 'platform_favorite_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_favorites', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'platform_favorite_id']);
            $table->unique(['platform_favorite_id']);
        });
    }
};
