<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ikas favori kimliği 'ikas:{customerUuid}:{productUuid}' = 78 karakter;
     * varchar(64) ilk gerçek webhook olayında 22001 truncation verdi (25 Tem).
     */
    public function up(): void
    {
        Schema::table('product_favorites', function (Blueprint $table) {
            $table->string('platform_favorite_id', 128)->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_favorites', function (Blueprint $table) {
            $table->string('platform_favorite_id', 64)->change();
        });
    }
};
